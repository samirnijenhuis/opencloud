<?php

namespace App\Domains\Backups\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Members\Models\User;

class DeleteBackupTarget
{
    /** New backups go to the disk of the dashboard again. Backups in the bucket stay there. */
    public function execute(BackupTarget $target, User $by): void
    {
        $context = ['endpoint' => $target->endpoint, 'bucket' => $target->bucket];

        $target->delete();

        AuditEvent::record($by, 'backup_target.deleted', null, $context);
    }
}
