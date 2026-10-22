<?php

namespace App\Jobs;

use App\Datastores\Engines;
use App\Kube\Kubectl;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Removes the user, key, or server of an attachment that DetachDatastore already deleted from the database.
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
        $attachment = (new DatastoreAttachment)->setRawAttributes($this->attachment)->setRelation('datastore', $this->datastore);

        Engines::for($this->datastore->kind)->detach(Kubectl::for($this->datastore->cluster), $attachment, $this->destroyData);
    }
}
