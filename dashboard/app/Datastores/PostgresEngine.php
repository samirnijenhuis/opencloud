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
final class PostgresEngine implements SqlEngine
{
    private const CLIENT = ['psql', '--username=postgres', '--dbname=postgres', '--set=ON_ERROR_STOP=1', '--quiet', '--no-psqlrc'];

    public function server(Datastore $datastore): array
    {
        return DatastoreManifests::postgres($datastore);
    }

    public function names(Environment $environment, ?string $name): array
    {
        return DatabaseConnection::names($environment, $name ?? DatabaseConnection::FIRST_NAME);
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
        return DatabaseConnection::env($attachment);
    }

    /** Without owners and grants in the dump, the restore can run as the role of the environment. */
    public function dump(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $sink): void
    {
        $this->stream($kubectl, $attachment, "pg_dump --username=postgres --no-owner --no-privileges {$attachment->database_name} | gzip", output: $sink);
    }

    public function restore(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $gzipDump): void
    {
        $name = $attachment->database_name;

        $this->run($kubectl, $attachment->datastore, [
            "DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE);",
            "CREATE DATABASE \"{$name}\" OWNER \"{$attachment->username}\";",
            "REVOKE ALL ON DATABASE \"{$name}\" FROM PUBLIC;",
        ]);
        $this->stream($kubectl, $attachment, "gunzip | psql --username={$attachment->username} --dbname={$name} --set=ON_ERROR_STOP=1 --quiet --no-psqlrc", stdin: $gzipDump);
    }

    /**
     * @param  resource|null  $stdin
     * @param  resource|null  $output
     */
    private function stream(Kubectl $kubectl, DatastoreAttachment $attachment, string $pipeline, mixed $stdin = null, mixed $output = null): void
    {
        $command = ['bash', '-c', "set -o pipefail; {$pipeline}"];

        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($attachment->datastore), $command, $stdin, 'postgres', 3600, $output)->throw();
    }

    /** @param  list<string>  $statements */
    private function run(Kubectl $kubectl, Datastore $datastore, array $statements): void
    {
        $kubectl->exec(DatastoreManifests::NAMESPACE, DatastoreManifests::serverPod($datastore), self::CLIENT, implode("\n", $statements)."\n", 'postgres')->throw();
    }
}
