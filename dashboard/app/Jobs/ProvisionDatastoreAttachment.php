<?php

namespace App\Jobs;

use App\Datastores\DatastoreStatus;
use App\Datastores\Engine;
use App\Datastores\Engines;
use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Support\SecretMasker;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Makes sure the server of the datastore runs, then creates the database, user, bucket, or server of one attachment.
 * Each step converges, so a failed attachment can simply run again.
 */
class ProvisionDatastoreAttachment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public bool $deleteWhenMissingModels = true;

    private const SERVER_ROLLOUT_SECONDS = 600;

    public function __construct(public DatastoreAttachment $attachment) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("datastore-{$this->attachment->datastore_id}"))->releaseAfter(10)->expireAfter($this->timeout + 60)];
    }

    /** Waiting for the lock releases the job, so tries do not limit it. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(): void
    {
        $attachment = $this->attachment;
        $datastore = $attachment->datastore;
        $engine = Engines::for($datastore->kind);
        $kubectl = Kubectl::for($datastore->cluster);
        $masker = new SecretMasker(array_filter([$attachment->password, $datastore->root_password]));

        $attachment->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);

        try {
            $this->provisionServer($kubectl, $engine, $datastore, $masker);
            $engine->attach($kubectl, $attachment);
        } catch (Throwable $e) {
            $attachment->update(['status' => DatastoreStatus::Failed, 'error' => $masker->mask($e->getMessage())]);

            return;
        }

        $attachment->update(['status' => DatastoreStatus::Ready]);
    }

    /** The apply and the rollout check run each time, so a server that someone removed comes back. */
    private function provisionServer(Kubectl $kubectl, Engine $engine, Datastore $datastore, SecretMasker $masker): void
    {
        if ($datastore->status !== DatastoreStatus::Ready) {
            $datastore->update(['status' => DatastoreStatus::Provisioning, 'error' => null]);
        }

        try {
            $server = $engine->server($datastore);
            $kubectl->apply([DatastoreManifests::namespace(), ...$server]);

            if ($server !== []) {
                $kubectl->rolloutStatus(DatastoreManifests::NAMESPACE, 'statefulset', DatastoreManifests::serverName($datastore), self::SERVER_ROLLOUT_SECONDS)->throw();
            }
        } catch (Throwable $e) {
            $datastore->update(['status' => DatastoreStatus::Failed, 'error' => $masker->mask($e->getMessage())]);

            throw $e;
        }

        if ($datastore->status !== DatastoreStatus::Ready) {
            $datastore->update(['status' => DatastoreStatus::Ready]);
        }
    }
}
