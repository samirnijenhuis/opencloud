<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\BackupTarget;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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
    public function handle(User $by, string $endpoint, string $bucket, ?string $accessKey, ?string $secretKey): BackupTarget
    {
        $target = BackupTarget::query()->latest('id')->first() ?? new BackupTarget;
        $accessKey ??= $target->access_key;
        $secretKey ??= $target->secret_key;

        if ($accessKey === null || $secretKey === null) {
            throw new InvalidArgumentException('Enter the access key and the secret key.');
        }

        $this->check($endpoint, $bucket, $accessKey, $secretKey);

        $target->fill(['endpoint' => $endpoint, 'bucket' => $bucket, 'access_key' => $accessKey, 'secret_key' => $secretKey])->save();

        AuditEvent::record($by, 'backup_target.saved', $target, ['endpoint' => $endpoint, 'bucket' => $bucket]);

        return $target;
    }

    private function check(string $endpoint, string $bucket, string $accessKey, string $secretKey): void
    {
        $path = '.opencloud-check-'.Str::random(12);

        try {
            $disk = Storage::build([
                'driver' => 's3',
                'key' => $accessKey,
                'secret' => $secretKey,
                'region' => 'us-east-1',
                'bucket' => $bucket,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'throw' => true,
            ]);
            $disk->put($path, 'ok');
            $disk->delete($path);
        } catch (Throwable $e) {
            // The message can echo the request, so it never reaches the page.
            report($e);

            throw new InvalidArgumentException('The dashboard could not write to this bucket. Check the endpoint, the bucket, and the keys.');
        }
    }
}
