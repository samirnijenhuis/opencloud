<?php

namespace App\Domains\Backups\Jobs;

use App\Domains\Backups\DatabaseBackups;
use App\Domains\Datastores\Models\DatastoreAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Dumps the database of one attachment, for the "Back up now" button. */
class BackupDatabase implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public DatastoreAttachment $attachment)
    {
        $this->onQueue('builds');
    }

    public function handle(DatabaseBackups $backups): void
    {
        $backups->backup($this->attachment);
    }
}
