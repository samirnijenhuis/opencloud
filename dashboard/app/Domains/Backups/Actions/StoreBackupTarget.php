<?php

namespace App\Domains\Backups\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\BackupDisk;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Members\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class StoreBackupTarget
{
    /**
     * Writes and deletes a small test object, then saves the target. A null key keeps the saved key.
     *
     * @throws InvalidArgumentException when the bucket cannot be reached with these settings
     */
    public function execute(User $by, string $endpoint, string $bucket, ?string $accessKey, ?string $secretKey, string $region = 'us-east-1', bool $pathStyle = true): BackupTarget
    {
        $target = BackupTarget::query()->latest('id')->first() ?? new BackupTarget;
        $accessKey ??= $target->access_key;
        $secretKey ??= $target->secret_key;

        if ($accessKey === null || $secretKey === null) {
            throw new InvalidArgumentException('Enter the access key and the secret key.');
        }

        $target->fill(['endpoint' => $endpoint, 'bucket' => $bucket, 'region' => $region, 'path_style' => $pathStyle, 'access_key' => $accessKey, 'secret_key' => $secretKey]);
        $this->check($target);
        $target->save();

        AuditEvent::record($by, 'backup_target.saved', $target, ['endpoint' => $endpoint, 'bucket' => $bucket, 'region' => $region]);

        return $target;
    }

    private function check(BackupTarget $target): void
    {
        $path = '.opencloud-check-'.Str::random(12);

        try {
            $disk = BackupDisk::s3($target);
            $disk->put($path, 'ok');
            $disk->delete($path);
        } catch (Throwable $e) {
            // The message can echo the request, so it never reaches the page.
            report($e);

            throw new InvalidArgumentException('The dashboard could not write to this bucket. Check the endpoint, the bucket, and the keys.');
        }
    }
}
