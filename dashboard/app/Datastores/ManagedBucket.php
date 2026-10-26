<?php

namespace App\Datastores;

use App\Clusters\DigitalOceanCluster;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Tofu\Runner;
use App\Tofu\Stack;
use Illuminate\Support\Str;

/**
 * A DigitalOcean Spaces bucket for one environment, with a key that can read and write that bucket only.
 *
 * The provider creates a bucket through the S3 API of Spaces, which takes a Spaces key and not the API token. A
 * provider cannot take its credentials from a resource of the same apply, so two stacks run in order: spacesadmin
 * makes a full-access key with the API token, and bucket uses that key. The full-access key stays in the encrypted
 * state of its stack until the bucket is destroyed. The app gets only the bucket key.
 */
final class ManagedBucket implements ManagedStack
{
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
        $runner = app(Runner::class);
        $admin = $runner->apply($this->adminStack($attachment), $attachment->datastore);
        $outputs = $runner->apply($this->bucketStack($attachment, $admin), $attachment->datastore);

        return [
            'host' => (string) $outputs['endpoint'],
            'region' => (string) $outputs['region'],
            'database_name' => (string) $outputs['bucket'],
            'username' => (string) $outputs['access_key_id'],
            'password' => (string) $outputs['secret_key'],
            'path_style' => false,
        ];
    }

    /** The apply of spacesadmin gives the full-access key back, or makes it again after a failed destroy. */
    public function destroy(DatastoreAttachment $attachment): void
    {
        $runner = app(Runner::class);
        $admin = $runner->apply($this->adminStack($attachment), $attachment->datastore);
        $runner->destroy($this->bucketStack($attachment, $admin), $attachment->datastore);
        $runner->destroy($this->adminStack($attachment), $attachment->datastore);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return BucketConnection::of($attachment);
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
