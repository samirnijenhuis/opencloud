<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Support\Kube\Kubectl;

/**
 * One Postgres server per cluster, with one database and one role per environment. The image trusts local socket
 * connections, so psql in the pod needs no password.
 */
final class LocalPostgres extends LocalSql
{
    private const CLIENT = ['psql', '--username=postgres', '--dbname=postgres', '--set=ON_ERROR_STOP=1', '--quiet', '--no-psqlrc'];

    protected function kind(): DatastoreKind
    {
        return DatastoreKind::Postgres;
    }

    protected function container(): string
    {
        return 'postgres';
    }

    protected function client(): array
    {
        return self::CLIENT;
    }

    protected function server(Datastore $datastore): array
    {
        return DatastoreManifests::postgres($datastore);
    }

    /** Every role can connect to a new database by default, so the attach revokes that from PUBLIC. */
    protected function grant(Kubectl $kubectl, DatastoreAttachment $attachment): void
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
    protected function revoke(Kubectl $kubectl, DatastoreAttachment $attachment, bool $destroyData): void
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

    /** Without owners and grants in the dump, the restore can run as the role of the environment. */
    protected function dumpPipeline(DatastoreAttachment $attachment): string
    {
        return "pg_dump --username=postgres --no-owner --no-privileges {$attachment->database_name} | gzip";
    }

    protected function restoreInto(Kubectl $kubectl, DatastoreAttachment $attachment, mixed $gzipDump): void
    {
        $name = $attachment->database_name;

        $this->run($kubectl, $attachment->datastore, [
            "DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE);",
            "CREATE DATABASE \"{$name}\" OWNER \"{$attachment->username}\";",
            "REVOKE ALL ON DATABASE \"{$name}\" FROM PUBLIC;",
        ]);
        $this->stream($kubectl, $attachment, "gunzip | psql --username={$attachment->username} --dbname={$name} --set=ON_ERROR_STOP=1 --quiet --no-psqlrc", stdin: $gzipDump);
    }
}
