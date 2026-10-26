<?php

namespace App\Datastores;

use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * One MySQL server per cluster, with one database and one user per environment. SQL goes in on stdin, so no
 * password shows up in a process list.
 */
final class MysqlEngine implements SqlEngine
{
    private const CLIENT = ['bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'];

    public function server(Datastore $datastore): array
    {
        return DatastoreManifests::mysql($datastore);
    }

    public function names(Environment $environment, ?string $name): array
    {
        return DatabaseNames::names($environment, $name ?? DatabaseNames::FIRST_NAME);
    }

    public function attach(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        ['database' => $database, 'user' => $user] = $this->quoted($attachment);

        $this->run($kubectl, $attachment->datastore, [
            "CREATE DATABASE IF NOT EXISTS {$database};",
            "CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY '{$attachment->password}';",
            "ALTER USER {$user} IDENTIFIED BY '{$attachment->password}';",
            "GRANT ALL PRIVILEGES ON {$database}.* TO {$user};",
        ]);
    }

    public function detach(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        ['database' => $database, 'user' => $user] = $this->quoted($attachment);

        $this->run($kubectl, $attachment->datastore, [
            "DROP USER IF EXISTS {$user};",
            ...($destroyData ? ["DROP DATABASE IF EXISTS {$database};"] : []),
        ]);
    }

    public function env(DatastoreAttachment $attachment): array
    {
        return DatabaseConnection::env($attachment);
    }

    public function connection(DatastoreAttachment $attachment): ?Connection
    {
        return SqlConnection::of($attachment);
    }

    public function dump(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $sink): void
    {
        $this->stream($kubectl, $attachment, 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces '.$attachment->database_name.' | gzip', output: $sink);
    }

    public function restore(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $gzipDump): void
    {
        ['database' => $database] = $this->quoted($attachment);

        $this->run($kubectl, $attachment->datastore, ["DROP DATABASE IF EXISTS {$database};", "CREATE DATABASE {$database};"]);
        $this->stream($kubectl, $attachment, 'gunzip | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root '.$attachment->database_name, stdin: $gzipDump);
    }

    /**
     * @param  resource|null  $stdin
     * @param  resource|null  $output
     */
    private function stream(Kubectl $kubectl, DatastoreAttachment $attachment, string $pipeline, mixed $stdin = null, mixed $output = null): void
    {
        $command = ['bash', '-c', "set -o pipefail; {$pipeline}"];

        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($attachment->datastore), $command, $stdin, 'mysql', 3600, $output)->throw();
    }

    /** @param  list<string>  $statements */
    private function run(Kubectl $kubectl, Datastore $datastore, array $statements): void
    {
        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($datastore), self::CLIENT, implode("\n", $statements)."\n", 'mysql')->throw();
    }

    /** @return array{database: string, user: string} */
    private function quoted(DatastoreAttachment $attachment): array
    {
        return ['database' => "`{$attachment->database_name}`", 'user' => "'{$attachment->username}'@'%'"];
    }
}
