<?php

namespace App\Domains\Datastores;

use App\Domains\Clusters\DigitalOceanCluster;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Support\Tofu\Runner;
use App\Support\Tofu\Stack;
use Closure;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A DigitalOcean Spaces bucket for one environment, with a key that can read and write that bucket only.
 *
 * The provider creates a bucket through the S3 API of Spaces, which takes a Spaces key and not the API token. A
 * provider cannot take its credentials from a resource of the same apply, so two stacks run in order: spacesadmin
 * makes a full-access key with the API token, and bucket uses that key. The full-access key lives only during one
 * run. Each run applies spacesadmin first and destroys it last, also when the bucket step fails, so no full-access key
 * stays in the account between runs. A run that stops between the steps leaves the state of spacesadmin, and the next
 * run applies it again and destroys it. The app gets only the bucket key.
 */
final class ManagedBucket implements ManagedStack
{
    private const KEY_TRIES = 5;

    private const KEY_WAIT_SECONDS = 10;

    /** What the S3 API of Spaces answers for a key that it does not know. */
    private const UNKNOWN_KEY = '/InvalidAccessKeyId|access key id you provided does not exist|invalid access key/i';

    /** Spaces has no version. */
    public function version(): string
    {
        return '';
    }

    /**
     * A bucket name is unique in all of Spaces, so it gets a random end. It is stored now, so a retry and the destroy
     * use the same name.
     */
    public function names(Environment $environment, ?string $name): array
    {
        $prefix = rtrim(substr("{$environment->application->cluster->name}-env-{$environment->id}", 0, 54), '-');

        return ['database_name' => $prefix.'-'.Str::lower(Str::random(8))];
    }

    public function apply(DatastoreAttachment $attachment): array
    {
        $outputs = $this->withAdminKey($attachment, fn (array $admin) => $this->untilKeyWorks(
            fn () => app(Runner::class)->apply($this->bucketStack($attachment, $admin), $attachment->datastore),
        ));

        return [
            'host' => (string) $outputs['endpoint'],
            'region' => (string) $outputs['region'],
            'database_name' => (string) $outputs['bucket'],
            'username' => (string) $outputs['access_key_id'],
            'password' => (string) $outputs['secret_key'],
            'path_style' => false,
        ];
    }

    public function destroy(DatastoreAttachment $attachment): void
    {
        $this->withAdminKey($attachment, fn (array $admin) => $this->untilKeyWorks(
            fn () => app(Runner::class)->destroy($this->bucketStack($attachment, $admin), $attachment->datastore),
        ));
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return BucketConnection::of($attachment);
    }

    /**
     * Applies spacesadmin, runs $use with its outputs, and destroys spacesadmin. A failed $use destroys it too, and
     * the error of $use stays the error of the run. A failed destroy after a good $use fails the run, so the next
     * run destroys the key.
     *
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $use
     * @return T
     */
    private function withAdminKey(DatastoreAttachment $attachment, Closure $use): mixed
    {
        $runner = app(Runner::class);

        try {
            $result = $use($runner->apply($this->adminStack($attachment), $attachment->datastore));
        } catch (Throwable $e) {
            try {
                $runner->destroy($this->adminStack($attachment), $attachment->datastore);
            } catch (Throwable) {
                // The first error is the one that the owner needs to see.
            }

            throw $e;
        }

        $runner->destroy($this->adminStack($attachment), $attachment->datastore);

        return $result;
    }

    /**
     * Spaces may not know a new key at once. Only an error about the access key runs the step again.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    private function untilKeyWorks(Closure $step): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $step();
            } catch (RuntimeException $e) {
                if ($try === self::KEY_TRIES || ! preg_match(self::UNKNOWN_KEY, $e->getMessage())) {
                    throw $e;
                }

                Sleep::for(self::KEY_WAIT_SECONDS)->seconds();
            }
        }
    }

    private function adminStack(DatastoreAttachment $attachment): Stack
    {
        return new Stack('digitalocean/spaces-admin-key', "spacesadmin-{$attachment->datastore_id}", [
            'name' => "{$attachment->database_name}-opentofu",
        ], ['DIGITALOCEAN_TOKEN' => DigitalOceanCluster::token()]);
    }

    /** @param  array<string, mixed>  $admin  the outputs of the spacesadmin stack */
    private function bucketStack(DatastoreAttachment $attachment, array $admin): Stack
    {
        return new Stack('digitalocean/bucket', "bucket-{$attachment->datastore_id}", [
            'name' => $attachment->database_name,
            'region' => $attachment->datastore->cluster->region,
        ], [
            'DIGITALOCEAN_TOKEN' => DigitalOceanCluster::token(),
            'SPACES_ACCESS_KEY_ID' => (string) $admin['access_key_id'],
            'SPACES_SECRET_ACCESS_KEY' => (string) $admin['secret_key'],
        ]);
    }
}
