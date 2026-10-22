<?php

namespace App\Backups;

use App\Models\Backup;
use App\Models\BackupTarget;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where backups go: the S3 bucket of the backup target when one exists, else the data folder of the dashboard.
 */
final class BackupDisk
{
    public const LOCAL = 'local';

    public const S3 = 's3';

    /** @return array{0: string, 1: Filesystem} the disk name for the backup row and the disk */
    public static function current(): array
    {
        $target = self::target();

        return $target !== null ? [self::S3, self::s3($target)] : [self::LOCAL, self::local()];
    }

    public static function for(Backup $backup): Filesystem
    {
        if ($backup->disk === self::LOCAL) {
            return self::local();
        }

        return self::s3(self::target() ?? throw new RuntimeException('The backup target of this backup no longer exists.'));
    }

    public static function target(): ?BackupTarget
    {
        return BackupTarget::query()->latest('id')->first();
    }

    private static function local(): Filesystem
    {
        return Storage::build(['driver' => 'local', 'root' => config('opencloud.data_path'), 'throw' => true]);
    }

    private static function s3(BackupTarget $target): Filesystem
    {
        return Storage::build([
            'driver' => 's3',
            'key' => $target->access_key,
            'secret' => $target->secret_key,
            'region' => 'us-east-1',
            'bucket' => $target->bucket,
            'endpoint' => $target->endpoint,
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);
    }
}
