<?php

namespace App\Jobs;

use App\Datastores\Provisioners;
use App\Models\DatastoreAttachment;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * The queue side of the local source: one lock per datastore, because the attachments of one shared server must not
 * apply its StatefulSet at the same time. LocalProvisioner dispatches it, and LocalProvisioner::provision() does the work.
 */
class ProvisionDatastoreAttachment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public bool $deleteWhenMissingModels = true;

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
        Provisioners::of($this->attachment->datastore)->provision($this->attachment);
    }
}
