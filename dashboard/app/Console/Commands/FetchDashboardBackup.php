<?php

namespace App\Console\Commands;

use App\Backups\BackupDisk;
use App\Restore\DashboardBackup;
use App\Restore\RestoreState;
use App\Support\SecretMasker;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The first part of a restore. The installer runs it in a one-off Job with the dashboard image, before the dashboard starts.
 */
class FetchDashboardBackup extends Command
{
    protected $signature = 'opencloud:fetch-dashboard-backup
        {file : The restore file with the backup target and the APP_KEY}
        {--database=/var/lib/opencloud/data/database.sqlite : Where the dashboard database goes}
        {--app-key-file=/var/lib/opencloud/APP_KEY : Where the APP_KEY goes}';

    protected $description = 'Restore the dashboard database from the newest copy in the backup target';

    private const REQUIRED = ['BACKUP_ENDPOINT', 'BACKUP_BUCKET', 'BACKUP_ACCESS_KEY', 'BACKUP_SECRET_KEY', 'APP_KEY'];

    public function handle(): int
    {
        $database = $this->option('database');

        if (is_file($database) && filesize($database) > 0) {
            $this->error("The dashboard database {$database} exists. A restore works only on a new server.");

            return self::FAILURE;
        }

        $values = array_filter(Dotenv::parse((string) file_get_contents($this->argument('file'))), fn (?string $value) => $value !== null && $value !== '');
        $missing = array_diff(self::REQUIRED, array_keys($values));

        if ($missing !== []) {
            $this->error('The restore file has no value for '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $masker = new SecretMasker([$values['BACKUP_ACCESS_KEY'], $values['BACKUP_SECRET_KEY'], $values['APP_KEY']]);
        File::ensureDirectoryExists(dirname($database));

        try {
            $bucket = BackupDisk::bucket(
                $values['BACKUP_ENDPOINT'],
                $values['BACKUP_BUCKET'],
                $values['BACKUP_REGION'] ?? null,
                isset($values['BACKUP_PATH_STYLE']) ? filter_var($values['BACKUP_PATH_STYLE'], FILTER_VALIDATE_BOOL) : null,
                $values['BACKUP_ACCESS_KEY'],
                $values['BACKUP_SECRET_KEY'],
            );
            $copy = (new DashboardBackup($bucket))->restore($values['APP_KEY'], $database, $this->option('app-key-file'));
        } catch (Throwable $e) {
            $this->error($masker->mask($e->getMessage()));

            return self::FAILURE;
        }

        RestoreState::begin();
        $this->info("Restored the dashboard database from {$copy} in the bucket {$values['BACKUP_BUCKET']}.");

        return self::SUCCESS;
    }
}
