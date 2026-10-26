<?php

namespace App\Actions;

use App\Backups\BackupStatus;
use App\Enums\DatastoreSource;
use App\Jobs\RestoreDatabase;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\User;
use InvalidArgumentException;

class RestoreBackup
{
    /**
     * Replaces the database with the backup. The UI asks the user to confirm first, because the current data goes away.
     * The restore job backs up the current data before it starts.
     *
     * @throws InvalidArgumentException when the backup did not finish, its database is no longer attached, or it is a managed database
     */
    public function handle(Backup $backup, User $by): void
    {
        if ($backup->status !== BackupStatus::Done) {
            throw new InvalidArgumentException('Only a finished backup can be restored.');
        }

        if ($backup->currentAttachment() === null) {
            throw new InvalidArgumentException('The database of this backup is no longer attached. Attach it again to restore it.');
        }

        if ($backup->datastore->source === DatastoreSource::Managed) {
            throw new InvalidArgumentException('DigitalOcean backs up a managed database itself.');
        }

        AuditEvent::record($by, 'backup.restore_requested', $backup, ['datastore_id' => $backup->datastore_id, 'database_name' => $backup->database_name]);
        RestoreDatabase::dispatch($backup, $by)->afterCommit();
    }
}
