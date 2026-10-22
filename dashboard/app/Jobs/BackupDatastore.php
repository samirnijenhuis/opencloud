<?php

namespace App\Jobs;

use App\Backups\DatabaseBackups;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Dumps the database of each attachment of one MySQL or Postgres datastore. One failed dump does not stop the others.
 */
class BackupDatastore implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Datastore $datastore) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("backup-datastore-{$this->datastore->id}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(DatabaseBackups $backups): void
    {
        $this->datastore->attachments()->with('datastore')->each(fn (DatastoreAttachment $attachment) => $backups->backup($attachment));
    }
}
