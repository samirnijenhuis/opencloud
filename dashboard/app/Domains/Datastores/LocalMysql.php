<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Support\Kube\Kubectl;

/**
 * One MySQL server per cluster, with one database and one user per environment. SQL goes in on stdin, so no
 * password shows up in a process list.
 */
final class LocalMysql extends LocalSql
{
    private const CLIENT = ['bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'];

    protected function kind(): DatastoreKind
    {
        return DatastoreKind::Mysql;
    }

    protected function container(): string
    {
        return 'mysql';
    }

    protected function client(): array
    {
        return self::CLIENT;
    }

    protected function server(Datastore $datastore): array
    {
        return DatastoreManifests::mysql($datastore);
    }

    protected function grant(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        ['database' => $database, 'user' => $user] = $this->quoted($attachment);

        $this->run($kubectl, $attachment->datastore, [
            "CREATE DATABASE IF NOT EXISTS {$database};",
            "CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY '{$attachment->password}';",
            "ALTER USER {$user} IDENTIFIED BY '{$attachment->password}';",
            "GRANT ALL PRIVILEGES ON {$database}.* TO {$user};",
        ]);
    }

    protected function revoke(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        ['database' => $database, 'user' => $user] = $this->quoted($attachment);

        $this->run($kubectl, $attachment->datastore, [
            "DROP USER IF EXISTS {$user};",
            ...($destroyData ? ["DROP DATABASE IF EXISTS {$database};"] : []),
        ]);
    }

    protected function dumpPipeline(DatastoreAttachment $attachment): string
    {
        return 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces '.$attachment->database_name.' | gzip';
    }

    protected function restoreInto(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $gzipDump): void
    {
        ['database' => $database] = $this->quoted($attachment);

        $this->run($kubectl, $attachment->datastore, ["DROP DATABASE IF EXISTS {$database};", "CREATE DATABASE {$database};"]);
        $this->stream($kubectl, $attachment, 'gunzip | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root '.$attachment->database_name, stdin: $gzipDump);
    }

    /** @return array{database: string, user: string} */
    private function quoted(DatastoreAttachment $attachment): array
    {
        return ['database' => "`{$attachment->database_name}`", 'user' => "'{$attachment->username}'@'%'"];
    }
}
