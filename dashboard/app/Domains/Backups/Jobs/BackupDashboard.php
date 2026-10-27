<?php

namespace App\Domains\Backups\Jobs;

use App\Domains\Backups\BackupDisk;
use App\Domains\Backups\DatabaseBackups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Copies the SQLite database of the dashboard with VACUUM INTO, which gives a consistent copy while the dashboard runs.
 * The copy is useless without APP_KEY, because the secrets in it are encrypted with that key.
 */
class BackupDashboard implements ShouldQueue
{
    use Queueable;

    public const FOLDER = 'backups/dashboard';

    public function handle(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        [, $disk] = BackupDisk::current();
        $copy = tempnam(sys_get_temp_dir(), 'dashboard-');
        unlink($copy);

        try {
            DB::statement('VACUUM INTO ?', [$copy]);
            $source = fopen($copy, 'rb');
            stream_filter_append($source, 'zlib.deflate', STREAM_FILTER_READ, ['window' => 31]);
            // The S3 client reads the body to sign it and then rewinds it. A filtered stream cannot rewind, a temporary file can.
            $gzip = tmpfile();
            stream_copy_to_stream($source, $gzip);
            fclose($source);
            rewind($gzip);
            $disk->writeStream(self::FOLDER.'/'.now()->format('Ymd-His').'.sqlite.gz', $gzip);
            fclose($gzip);
        } finally {
            if (file_exists($copy)) {
                unlink($copy);
            }
        }

        $files = collect($disk->files(self::FOLDER))->filter(fn (string $path) => str_ends_with($path, '.sqlite.gz'))->sortDesc()->values();

        foreach ($files->slice(DatabaseBackups::KEEP) as $old) {
            $disk->delete($old);
        }
    }
}
