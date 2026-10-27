<?php

namespace App\Domains\Backups\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Jobs\BackupDatabase;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Members\Models\User;
use InvalidArgumentException;

class BackupNow
{
    /** @throws InvalidArgumentException when the attachment has no database to dump, for example Valkey or a managed database */
    public function execute(DatastoreAttachment $attachment, User $by): void
    {
        $capabilities = Provisioners::of($attachment->datastore)->capabilities();

        if ($capabilities->dumps === null) {
            throw new InvalidArgumentException($capabilities->noDumps);
        }

        AuditEvent::record($by, 'backup.requested', $attachment, ['environment_id' => $attachment->environment_id, 'database_name' => $attachment->database_name]);
        BackupDatabase::dispatch($attachment)->afterCommit();
    }
}
