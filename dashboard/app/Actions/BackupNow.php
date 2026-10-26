<?php

namespace App\Actions;

use App\Datastores\Provisioners;
use App\Jobs\BackupDatabase;
use App\Models\AuditEvent;
use App\Models\DatastoreAttachment;
use App\Models\User;
use InvalidArgumentException;

class BackupNow
{
    /** @throws InvalidArgumentException when the attachment has no database to dump, for example Valkey or a managed database */
    public function handle(DatastoreAttachment $attachment, User $by): void
    {
        $capabilities = Provisioners::of($attachment->datastore)->capabilities();

        if ($capabilities->dumps === null) {
            throw new InvalidArgumentException($capabilities->noDumps);
        }

        AuditEvent::record($by, 'backup.requested', $attachment, ['environment_id' => $attachment->environment_id, 'database_name' => $attachment->database_name]);
        BackupDatabase::dispatch($attachment)->afterCommit();
    }
}
