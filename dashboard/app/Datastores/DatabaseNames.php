<?php

namespace App\Datastores;

use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\Environment;

/**
 * The rules of one named MySQL or Postgres database that do not depend on the app framework: the connection name, the
 * database and the user on the server, and the address of a local server.
 */
final class DatabaseNames
{
    /** The name of the first database of an environment, when the user gives none. */
    public const FIRST_NAME = 'main';

    /** Laravel ships connections with these names, so a line for one of them in config/database.php would replace it. */
    public const RESERVED = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];

    /** The port of a local server. */
    private const PORTS = ['mysql' => 3306, 'postgres' => 5432];

    /** @return string|null the reason the name is not valid, or null */
    public static function nameError(string $name): ?string
    {
        return match (true) {
            preg_match('/^[a-z][a-z0-9_]{0,29}\z/', $name) !== 1 => 'Use 1 to 30 lowercase letters, digits, and underscores. Start with a letter.',
            in_array($name, self::RESERVED, true) => "Laravel already has a connection with the name {$name}. Use a different name.",
            default => null,
        };
    }

    /**
     * The database "main" keeps the names that each environment had before databases had names, so its data stays.
     * The names come from the connection name, so a later attach with the same name finds the data again.
     *
     * @return array{database_name: string, username: string}
     */
    public static function names(Environment $environment, string $name): array
    {
        $base = "env_{$environment->id}";

        if ($name === self::FIRST_NAME) {
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
