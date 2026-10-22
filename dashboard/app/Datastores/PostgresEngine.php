<?php

namespace App\Datastores;

use App\Kube\Kubectl;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * One Postgres server per cluster, with one database and one role per environment. The image trusts local socket
 * connections, so psql in the pod needs no password.
 */
final class PostgresEngine implements Engine
{
    private const CLIENT = ['psql', '--username=postgres', '--dbname=postgres', '--set=ON_ERROR_STOP=1', '--quiet', '--no-psqlrc'];

    public function server(Datastore $datastore): array
    {
        return DatastoreManifests::postgres($datastore);
    }

    public function names(Environment $environment): array
    {
        return ['database_name' => "env_{$environment->id}", 'username' => "env_{$environment->id}"];
    }

    /** Every role can connect to a new database by default, so the attach revokes that from PUBLIC. */
    public function attach(Kubectl $kubectl, DatastoreAttachment $attachment): void
    {
        $name = $attachment->database_name;

        $this->run($kubectl, $attachment->datastore, [
            "SELECT 'CREATE ROLE \"{$attachment->username}\" LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$attachment->username}')\\gexec",
            "ALTER ROLE \"{$attachment->username}\" WITH LOGIN PASSWORD '{$attachment->password}';",
            "SELECT 'CREATE DATABASE \"{$name}\" OWNER \"{$attachment->username}\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$name}')\\gexec",
            "REVOKE ALL ON DATABASE \"{$name}\" FROM PUBLIC;",
        ]);
    }

    /** A role that owns objects cannot be dropped, so the tables move to postgres first and the data stays. */
    public function detach(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
    {
        $user = $attachment->username;

        $this->run($kubectl, $attachment->datastore, $destroyData ? [
            "DROP DATABASE IF EXISTS \"{$attachment->database_name}\" WITH (FORCE);",
            "DROP ROLE IF EXISTS \"{$user}\";",
        ] : [
            "SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$user}') AS found \\gset",
            '\if :found',
            "\\connect \"{$attachment->database_name}\"",
            "REASSIGN OWNED BY \"{$user}\" TO postgres;",
            "DROP OWNED BY \"{$user}\";",
            '\connect postgres',
            "DROP ROLE \"{$user}\";",
            '\endif',
        ]);
    }

    public function env(DatastoreAttachment $attachment): array
    {
        return [
            'DB_CONNECTION' => ['pgsql', false],
            'DB_HOST' => [DatastoreManifests::host(DatastoreManifests::serverName($attachment->datastore)), false],
            'DB_PORT' => ['5432', false],
            'DB_DATABASE' => [$attachment->database_name, false],
            'DB_USERNAME' => [$attachment->username, false],
            'DB_PASSWORD' => [$attachment->password, true],
        ];
    }

    /** @param  list<string>  $statements */
    private function run(Kubectl $kubectl, Datastore $datastore, array $statements): void
    {
        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($datastore), self::CLIENT, implode("\n", $statements)."\n", 'postgres')->throw();
    }
}
