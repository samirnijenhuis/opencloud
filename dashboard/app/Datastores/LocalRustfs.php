<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * One RustFS server per cluster, with one bucket and one IAM user per environment. A canned policy with the bucket
 * name allows the user that bucket only. The rc client in the server pod runs the admin commands. The nightly job
 * copies its buckets.
 */
final class LocalRustfs extends LocalProvisioner
{
    private const LOGIN = 'rc alias set local http://127.0.0.1:9000 "$RUSTFS_ACCESS_KEY" "$RUSTFS_SECRET_KEY" >/dev/null';

    /** rc exits with 5 when the user or the bucket does not exist. */
    private const NOT_FOUND = '[ $? -eq 5 ]';

    protected function kind(): DatastoreKind
    {
        return DatastoreKind::Rustfs;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities($this->noDatabase(), copiesBuckets: true);
    }

    protected function server(Datastore $datastore): array
    {
        return DatastoreManifests::rustfs($datastore);
    }

    protected function names(Environment $environment, ?string $name): array
    {
        return ['database_name' => "env-{$environment->id}", 'username' => "env-{$environment->id}"];
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

        return new BucketConnection("http://{$host}:9000", 'us-east-1', $attachment->database_name, $attachment->username, $attachment->password, pathStyle: true);
    }

    /** @param  list<string>  $lines */
    private function run(Kubectl $kubectl, Datastore $datastore, array $lines, ?string $stdin = null): void
    {
        $script = implode("\n", ['set -e', ...$lines]);

        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($datastore), ['sh', '-c', $script], $stdin, 'rc')->throw();
    }
}
