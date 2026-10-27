<?php

namespace App\Domains\Backups;

use App\Domains\Backups\Models\Backup;
use App\Domains\Backups\Models\BackupTarget;
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

    public static function s3(BackupTarget $target): Filesystem
    {
        return self::bucket($target->endpoint, $target->bucket, $target->region, $target->path_style, $target->access_key, $target->secret_key);
    }

    /** A restore reads the bucket before the dashboard database exists, so it passes the settings without a BackupTarget row. */
    public static function bucket(string $endpoint, string $bucket, ?string $region, ?bool $pathStyle, string $accessKey, string $secretKey): Filesystem
    {
        return Storage::build([
            'driver' => 's3',
            'key' => $accessKey,
            'secret' => $secretKey,
            'region' => $region ?? 'us-east-1',
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $pathStyle ?? true,
            'throw' => true,
        ]);
    }
}
