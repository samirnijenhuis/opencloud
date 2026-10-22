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
final class MysqlEngine implements Engine
{
    private const CLIENT = ['bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'];

    public function server(Datastore $datastore): array
    {
        return DatastoreManifests::mysql($datastore);
    }

    public function names(Environment $environment): array
    {
        return ['database_name' => "env_{$environment->id}", 'username' => "env_{$environment->id}"];
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
        return [
            'DB_CONNECTION' => ['mysql', false],
            'DB_HOST' => [DatastoreManifests::host(DatastoreManifests::serverName($attachment->datastore)), false],
            'DB_PORT' => ['3306', false],
            'DB_DATABASE' => [$attachment->database_name, false],
            'DB_USERNAME' => [$attachment->username, false],
            'DB_PASSWORD' => [$attachment->password, true],
        ];
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
