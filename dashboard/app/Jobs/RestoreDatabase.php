<?php

namespace App\Jobs;

use App\Backups\DatabaseBackups;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\User;
use App\Support\SecretMasker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Replaces one database with one backup. The audit log records the result. */
class RestoreDatabase implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /** A second try would drop the database again, so a failed restore stays failed. */
    public int $tries = 1;

    public function __construct(public Backup $backup, public ?User $by) {}

    public function handle(DatabaseBackups $backups): void
    {
        $context = ['datastore_id' => $this->backup->datastore_id, 'database_name' => $this->backup->database_name];

        try {
            $backups->restore($this->backup);
        } catch (Throwable $e) {
            $masker = new SecretMasker(array_filter([$this->backup->datastore->root_password, $this->backup->currentAttachment()?->password]));
            AuditEvent::record($this->by, 'backup.restore_failed', $this->backup, [...$context, 'error' => $masker->mask($e->getMessage())]);

            return;
        }

        AuditEvent::record($this->by, 'backup.restored', $this->backup, $context);
    }
}
