<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;

/**
 * One MySQL or Postgres attachment as a Laravel connection: its name rules, its database and user on the server, and
 * its env values. The default database of an environment sets the DB_* values, so a Laravel app needs no config.
 * Each other database sets one <NAME>_DB_URL value, and the app adds one line to config/database.php for it.
 */
final class DatabaseConnection
{
    /** The name of the first database of an environment, when the user gives none. */
    public const FIRST_NAME = 'main';

    /** Laravel ships connections with these names, so a line for one of them in config/database.php would replace it. */
    public const RESERVED = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];

    /** libpq and Node accept only postgres:// in a URL, and Laravel maps postgres to pgsql. */
    private const LARAVEL = [
        'mysql' => ['driver' => 'mysql', 'scheme' => 'mysql', 'port' => 3306],
        'postgres' => ['driver' => 'pgsql', 'scheme' => 'postgres', 'port' => 5432],
    ];

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

    /** @return array{host: string, port: int} the address of a standard server in the cluster */
    public static function server(Datastore $datastore): array
    {
        return [
            'host' => DatastoreManifests::host(DatastoreManifests::serverName($datastore)),
            'port' => self::LARAVEL[$datastore->kind->value]['port'],
        ];
    }

    /**
     * A managed database has no address before its apply, so it has no env values yet.
     *
     * @return array<string, array{0: string, 1: bool}> key => [value, is secret]
     */
    public static function env(DatastoreAttachment $attachment): array
    {
        if ($attachment->host === null) {
            return [];
        }

        $laravel = self::LARAVEL[$attachment->datastore->kind->value];

        if ($attachment->is_default) {
            return [
                'DB_CONNECTION' => [$laravel['driver'], false],
                'DB_HOST' => [$attachment->host, false],
                'DB_PORT' => [(string) $attachment->port, false],
                'DB_DATABASE' => [$attachment->database_name, false],
                'DB_USERNAME' => [$attachment->username, false],
                'DB_PASSWORD' => [$attachment->password, true],
            ];
        }

        $url = sprintf(
            '%s://%s:%s@%s:%d/%s',
            $laravel['scheme'],
            rawurlencode($attachment->username),
            rawurlencode($attachment->password),
            $attachment->host,
            $attachment->port,
            $attachment->database_name,
        );

        return [self::urlKey($attachment->name) => [$url, true]];
    }

    public static function urlKey(string $name): string
    {
        return strtoupper($name).'_DB_URL';
    }

    /** The line in config/database.php that makes the database a Laravel connection with its own name. */
    public static function configLine(DatastoreKind $kind, string $name): string
    {
        return "'{$name}' => ['driver' => '".self::LARAVEL[$kind->value]['driver']."', 'url' => env('".self::urlKey($name)."')],";
    }
}
