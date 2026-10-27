<?php

namespace App\Domains\Datastores;

use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Environments\Models\Environment;

/**
 * The names of one MySQL or Postgres database on the server, and the address of a local server. ResourceFamily holds
 * the rules of the name itself.
 */
final class DatabaseNames
{
    /** The port of a local server. */
    private const PORTS = ['mysql' => 3306, 'postgres' => 5432];

    /**
     * The database "main" keeps the names that each environment had before databases had names, so its data stays.
     * The names come from the connection name, so a later attach with the same name finds the data again.
     *
     * @return array{database_name: string, username: string}
     */
    public static function names(Environment $environment, string $name): array
    {
        $base = "env_{$environment->id}";

        if ($name === ResourceFamily::FIRST_NAME) {
            return ['database_name' => $base, 'username' => $base];
        }

        // MySQL allows 32 characters in a user name, so the user name holds a short hash of the connection name.
        return ['database_name' => "{$base}_{$name}", 'username' => "{$base}_".substr(sha1($name), 0, 8)];
    }

    /** @return array{host: string, port: int} the address of a local server in the cluster */
    public static function server(Datastore $datastore): array
    {
        return [
            'host' => DatastoreManifests::host(DatastoreManifests::serverName($datastore)),
            'port' => self::PORTS[$datastore->kind->value],
        ];
    }
}
