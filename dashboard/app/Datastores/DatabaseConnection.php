<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Models\DatastoreAttachment;

/**
 * One MySQL or Postgres attachment as a Laravel connection. The default database of an environment sets the DB_*
 * values, so a Laravel app needs no config. Each other database sets one <NAME>_DB_URL value, and the app adds one
 * line to config/database.php for it. Workstream B moves this into LaravelProfile and deletes this file.
 */
final class DatabaseConnection
{
    /** libpq and Node accept only postgres:// in a URL, and Laravel maps postgres to pgsql. */
    private const LARAVEL = [
        'mysql' => ['driver' => 'mysql', 'scheme' => 'mysql'],
        'postgres' => ['driver' => 'pgsql', 'scheme' => 'postgres'],
    ];

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
