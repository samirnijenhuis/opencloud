<?php

namespace App\Actions;

use App\Datastores\Engines;
use App\Datastores\SqlEngine;
use App\Jobs\BackupDatabase;
use App\Models\AuditEvent;
use App\Models\DatastoreAttachment;
use App\Models\User;
use InvalidArgumentException;

class BackupNow
{
    /** @throws InvalidArgumentException when the attachment has no database, for example Valkey */
    public function handle(DatastoreAttachment $attachment, User $by): void
    {
        $kind = $attachment->datastore->kind;

        if (! Engines::for($kind) instanceof SqlEngine) {
            throw new InvalidArgumentException("A {$kind->value} datastore has no database to back up.");
        }

        AuditEvent::record($by, 'backup.requested', $attachment, ['environment_id' => $attachment->environment_id, 'database_name' => $attachment->database_name]);
        BackupDatabase::dispatch($attachment)->afterCommit();
    }
}
