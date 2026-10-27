<?php

namespace App\Domains\Backups;

use App\Domains\Backups\Enums\BackupStatus;
use App\Domains\Backups\Models\Backup;
use App\Domains\Datastores\DatabaseDumps;
use App\Domains\Datastores\DatabaseNames;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Support\SecretMasker;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Dumps, keeps, and restores the database of one MySQL or Postgres attachment.
 */
final class DatabaseBackups
{
    public const KEEP = 7;

    /** The name of a dump file is its time in this format, so the names sort by time. */
    public const TIME_FORMAT = 'Ymd-His';

    /** Two databases of one environment can share a server. The database "main" keeps the folder from before names. */
    public static function folder(DatastoreAttachment $attachment): string
    {
        $folder = "backups/{$attachment->datastore_id}/{$attachment->environment_id}";

        return in_array($attachment->name, [null, DatabaseNames::FIRST_NAME], true) ? $folder : "{$folder}/{$attachment->name}";
    }

    /** A failed dump returns a failed backup with the error, so the nightly run goes on with the next database. */
    public function backup(DatastoreAttachment $attachment, bool $prune = true): Backup
    {
        $datastore = $attachment->datastore;
        [$diskName, $disk] = BackupDisk::current();
        $backup = $attachment->backups()->create([
            'datastore_id' => $datastore->id,
            'database_name' => $attachment->database_name,
            'disk' => $diskName,
            'location' => self::folder($attachment).'/'.now()->format(self::TIME_FORMAT).'.sql.gz',
            'status' => BackupStatus::Running,
        ]);
        $dump = tmpfile();

        try {
            $this->dumps($attachment)->dump($attachment, $dump);
            rewind($dump);
            $disk->writeStream($backup->location, $dump);
            $backup->update(['status' => BackupStatus::Done, 'size' => $disk->size($backup->location)]);
        } catch (Throwable $e) {
            $this->deleteFile($disk, $backup->location);
            $backup->update(['status' => BackupStatus::Failed, 'error' => (new SecretMasker(array_filter([$datastore->root_password, $attachment->password])))->mask($e->getMessage())]);

            return $backup;
        } finally {
            fclose($dump);
        }

        if ($prune) {
            $this->prune($attachment);
        }

        return $backup;
    }

    /**
     * Backs up the current database first, so a wrong restore can be undone.
     *
     * @throws RuntimeException when the backup before the restore fails, or the restore fails
     */
    public function restore(Backup $backup): void
    {
        $attachment = $backup->currentAttachment() ?? throw new InvalidArgumentException('The database of this backup is no longer attached.');
        $before = $this->backup($attachment, prune: false);

        if ($before->status === BackupStatus::Failed) {
            throw new RuntimeException("The backup before the restore failed, so the restore did not start. {$before->error}");
        }

        $dump = BackupDisk::for($backup)->readStream($backup->location);

        try {
            $this->dumps($attachment)->restore($attachment, $dump);
        } finally {
            fclose($dump);
        }

        $this->prune($attachment);
    }

    /** Keeps the newest KEEP finished backups of the database and removes every older backup. */
    public function prune(DatastoreAttachment $attachment): void
    {
        $backups = Backup::query()
            ->where('datastore_id', $attachment->datastore_id)
            ->where('database_name', $attachment->database_name)
            ->orderByDesc('id')
            ->get();
        $oldestKept = $backups->where('status', BackupStatus::Done)->values()->get(self::KEEP - 1);

        if ($oldestKept === null) {
            return;
        }

        foreach ($backups->where('id', '<', $oldestKept->id) as $old) {
            $this->deleteFile(BackupDisk::for($old), $old->location);
            $old->delete();
        }
    }

    private function dumps(DatastoreAttachment $attachment): DatabaseDumps
    {
        $capabilities = Provisioners::of($attachment->datastore)->capabilities();

        return $capabilities->dumps ?? throw new InvalidArgumentException($capabilities->noDumps);
    }

    private function deleteFile(Filesystem $disk, string $path): void
    {
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
