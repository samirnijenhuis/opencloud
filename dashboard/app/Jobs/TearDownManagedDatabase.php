<?php

namespace App\Jobs;

use App\Clusters\DigitalOceanCluster;
use App\Datastores\DatastoreStatus;
use App\Datastores\ManagedDatabase;
use App\Models\AuditEvent;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Tofu\Runner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs tofu destroy for a managed database, then removes the attachment and the datastore. A failed destroy keeps
 * both with the error, so "Detach" can run it again.
 */
class TearDownManagedDatabase implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3000;

    public bool $deleteWhenMissingModels = true;

    /** @param  array<string, mixed>  $attachment  the attributes of the attachment, without the password */
    public function __construct(public Datastore $datastore, public array $attachment)
    {
        $this->onQueue('builds');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("tofu-database-{$this->datastore->id}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(Runner $runner): void
    {
        $datastore = $this->datastore;
        $attachment = (new DatastoreAttachment)->setRawAttributes($this->attachment)->setRelation('datastore', $datastore);
        $datastore->update(['status' => DatastoreStatus::Destroying, 'error' => null, 'tofu_log' => null]);

        try {
            $runner->destroy(ManagedDatabase::stack($attachment, DigitalOceanCluster::token()), $datastore);
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        DB::transaction(function () use ($datastore) {
            AuditEvent::record(null, 'datastore.destroyed', $datastore, ['kind' => $datastore->kind->value, 'source' => 'managed']);
            $datastore->attachments()->delete();
            $datastore->delete();
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->datastore->update(['status' => DatastoreStatus::Failed, 'error' => 'The destroy failed. '.($e?->getMessage() ?? '')]);
    }
}
