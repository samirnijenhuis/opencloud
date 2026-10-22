<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\BackupTarget;
use App\Models\User;

class DeleteBackupTarget
{
    /** New backups go to the disk of the dashboard again. Backups in the bucket stay there. */
    public function handle(BackupTarget $target, User $by): void
    {
        $context = ['endpoint' => $target->endpoint, 'bucket' => $target->bucket];

        $target->delete();

        AuditEvent::record($by, 'backup_target.deleted', null, $context);
    }
}
