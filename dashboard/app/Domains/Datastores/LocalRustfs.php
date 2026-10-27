<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;

/**
 * One RustFS server per cluster, with one bucket and one IAM user per bucket of an environment. A canned policy with
 * the name of the user allows the user that bucket only. The rc client in the server pod runs the admin commands. The
 * nightly job copies its buckets.
 */
final class LocalRustfs extends LocalProvisioner
{
    private const LOGIN = 'rc alias set local http://127.0.0.1:9000 "$RUSTFS_ACCESS_KEY" "$RUSTFS_SECRET_KEY" >/dev/null';

    /** rc exits with 5 when the user or the bucket does not exist. */
    private const NOT_FOUND = '[ $? -eq 5 ]';

    protected function kind(): DatastoreKind
    {
        return DatastoreKind::RUSTFS;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities($this->noDatabase(), copiesBuckets: true);
    }

    protected function server(Datastore $datastore): array
    {
        return DatastoreManifests::rustfs($datastore);
    }

    /**
     * The bucket "main" keeps the names that each environment had before buckets had names, so its data stays. An S3
     * bucket name allows no underscore, so another bucket, its user, and its policy get a short hash of the name, like
     * the user of another database.
     */
    protected function names(Environment $environment, ?string $name): array
    {
        $base = "env-{$environment->id}";
        $name ??= ResourceFamily::FIRST_NAME;
        $server = $name === ResourceFamily::FIRST_NAME ? $base : "{$base}-".substr(sha1($name), 0, 8);

        return ['database_name' => $server, 'username' => $server];
    }

    /** The secret key comes in on stdin, so it is not in the argv of kubectl. */
    protected function grant(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        $bucket = $attachment->database_name;
        $user = $attachment->username;
        $policy = json_encode([
            'Version' => '2012-10-17',
            'Statement' => [['Effect' => 'Allow', 'Action' => ['s3:*'], 'Resource' => ["arn:aws:s3:::{$bucket}", "arn:aws:s3:::{$bucket}/*"]]],
        ], JSON_UNESCAPED_SLASHES);

        $this->run($kubectl, $attachment->datastore, [
            'read -r secret',
            self::LOGIN,
            "rc bucket create --ignore-existing local/{$bucket} >/dev/null",
            "printf '%s' '{$policy}' > /tmp/{$user}.json",
            "rc admin policy create local {$user} /tmp/{$user}.json >/dev/null",
            "rc admin user add local {$user} \"\$secret\" >/dev/null",
            "rc admin policy attach local {$user} --user {$user} >/dev/null",
        ], $attachment->password."\n");
    }

    protected function revoke(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        $user = $attachment->username;

        $this->run($kubectl, $attachment->datastore, [
            self::LOGIN,
            "rc admin user rm local {$user} >/dev/null || ".self::NOT_FOUND,
            "rc admin policy rm local {$user} >/dev/null",
            ...($destroyData ? ["rc bucket remove --force local/{$attachment->database_name} >/dev/null || ".self::NOT_FOUND] : []),
        ]);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        $host = DatastoreManifests::host(DatastoreManifests::serverName($attachment->datastore));

        return new BucketConnection("http://{$host}:9000", 'us-east-1', $attachment->database_name, $attachment->username, $attachment->password, true, $attachment->name, $attachment->is_default);
    }

    /** @param  list<string>  $lines */
    private function run(Kubectl $kubectl, Datastore $datastore, array $lines, ?string $stdin = null): void
    {
        $script = implode("\n", ['set -e', ...$lines]);

        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($datastore), ['sh', '-c', $script], $stdin, 'rc')->throw();
    }
}
