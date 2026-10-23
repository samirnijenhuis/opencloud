<?php

namespace App\Jobs;

use App\Clusters\DigitalOceanCluster;
use App\Datastores\DatastoreStatus;
use App\Datastores\ManagedDatabase;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Models\DatastoreAttachment;
use App\Tofu\Runner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies tofu/digitalocean/database for one attachment and writes its Laravel env values. DigitalOcean generates the
 * password, so the env values exist only after the apply. A second run converges.
 */
class ProvisionManagedDatabase implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3000;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public DatastoreAttachment $attachment)
    {
        $this->onQueue('builds');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("tofu-database-{$this->attachment->datastore_id}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(Runner $runner): void
    {
        $attachment = $this->attachment;
        $datastore = $attachment->datastore;

        if ($datastore->status === DatastoreStatus::Destroying) {
            return;
        }

        $attachment->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);
        $datastore->update(['status' => DatastoreStatus::Provisioning, 'error' => null, 'tofu_log' => null]);

        try {
            $outputs = $runner->apply(ManagedDatabase::stack($attachment, DigitalOceanCluster::token()), $datastore);
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        DB::transaction(function () use ($attachment, $datastore, $outputs) {
            $attachment->update(['password' => $outputs['password']]);

            foreach (ManagedDatabase::env($datastore->kind, $outputs) as $key => [$value, $isSecret]) {
                $attachment->environment->envVars()->updateOrCreate(['key' => $key, 'source' => EnvVarSource::Datastore], [
                    'value' => $value,
                    'is_secret' => $isSecret,
                    'available_at' => EnvVarAvailability::Runtime,
                    'datastore_attachment_id' => $attachment->id,
                ]);
            }

            $datastore->update(['status' => DatastoreStatus::Ready]);
            $attachment->update(['status' => DatastoreStatus::Ready]);
        });
    }

    public function failed(?Throwable $e): void
    {
        $error = $e?->getMessage() ?? 'The OpenTofu run failed.';

        $this->attachment->update(['status' => DatastoreStatus::Failed, 'error' => $error]);
        $this->attachment->datastore->update(['status' => DatastoreStatus::Failed, 'error' => $error]);
    }
}
