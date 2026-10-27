<?php

namespace App\Domains\Datastores\Jobs;

use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Provisioners;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Removes the user, key, or server of an attachment that LocalProvisioner::detach() already deleted from the database.
 */
class RevokeDatastoreAccess implements ShouldQueue
{
    use Queueable;

    public int $backoff = 30;

    /** @param  array<string, mixed>  $attachment  the attributes of the deleted attachment, without the password */
    public function __construct(public Datastore $datastore, public array $attachment, public bool $destroyData) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("datastore-{$this->datastore->id}"))->releaseAfter(10)->expireAfter(1260)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(): void
    {
        Provisioners::of($this->datastore)->release($this->datastore, $this->attachment, $this->destroyData);
    }
}
