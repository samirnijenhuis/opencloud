<?php

namespace App\Domains\Datastores\Jobs;

use App\Domains\Datastores\ManagedResource;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Provisioners;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Destroys a managed resource and its data with OpenTofu, on the builds queue with the lock of its apply.
 * ManagedResource::release() does the work. A failed destroy keeps the records with the error, so "Detach" can run it
 * again. The class keeps its old name, so jobs already in the queue still run.
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

    public function handle(): void
    {
        Provisioners::of($this->datastore)->release($this->datastore, $this->attachment, destroyData: true);
    }

    public function failed(?Throwable $e): void
    {
        ManagedResource::destroyFailed($this->datastore, $e);
    }
}
