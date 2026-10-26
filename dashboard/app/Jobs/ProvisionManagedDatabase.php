<?php

namespace App\Jobs;

use App\Datastores\ManagedDatabase;
use App\Datastores\Provisioners;
use App\Models\DatastoreAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * The queue side of the managed source: OpenTofu runs on the builds queue, one apply per datastore at a time.
 * ManagedDatabase dispatches it, and ManagedDatabase::provision() does the work.
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

    public function handle(): void
    {
        Provisioners::of($this->attachment->datastore)->provision($this->attachment);
    }

    public function failed(?Throwable $e): void
    {
        ManagedDatabase::failed($this->attachment, $e);
    }
}
