<?php

namespace App\Domains\Datastores\Jobs;

use App\Domains\Datastores\ManagedResource;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * The queue side of the managed source: OpenTofu runs on the builds queue, one apply per datastore at a time.
 * ManagedResource dispatches it for every managed kind, and ManagedResource::provision() does the work. The class
 * keeps its old name, so jobs already in the queue still run.
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
        ManagedResource::failed($this->attachment, $e);
    }
}
