<?php

namespace App\Datastores\Env;

use App\Datastores\BucketConnection;
use App\Datastores\KeyValueConnection;
use App\Datastores\SqlConnection;
use App\Enums\DatastoreKind;

/**
 * The names that a stock Laravel app reads without config. The default database sets the DB_* values, so a Laravel
 * app needs no config. Each other database sets one <NAME>_DB_URL value, and the app adds one line to
 * config/database.php for it.
 *
 * An attach also changes app behavior: Valkey sets CACHE_STORE=redis and RustFS sets FILESYSTEM_DISK=s3, like Laravel
 * Cloud does for a PHP app. Those values belong to this profile, not to the resource.
 */
final class LaravelProfile implements EnvProfile
{
    /** Laravel names the Postgres driver pgsql. */
    private const DRIVERS = ['mysql' => 'mysql', 'postgres' => 'pgsql'];

    /** libpq and Node accept only postgres:// in a URL, and Laravel maps postgres to pgsql. */
    private const SCHEMES = ['mysql' => 'mysql', 'postgres' => 'postgres'];

    public function database(SqlConnection $connection): array
    {
        if (! $connection->isDefault) {
            return [self::urlKey($connection->name) => EnvValue::secret($connection->url(self::SCHEMES[$connection->kind->value]))];
        }

        return [
            'DB_CONNECTION' => EnvValue::plain(self::DRIVERS[$connection->kind->value]),
            'DB_HOST' => EnvValue::plain($connection->host),
            'DB_PORT' => EnvValue::plain((string) $connection->port),
            'DB_DATABASE' => EnvValue::plain($connection->database),
            'DB_USERNAME' => EnvValue::plain($connection->username),
            'DB_PASSWORD' => EnvValue::secret($connection->password),
        ];
    }

    public function keyValue(KeyValueConnection $connection): array
    {
        return [
            'REDIS_CLIENT' => EnvValue::plain('phpredis'),
            'REDIS_HOST' => EnvValue::plain($connection->host),
            'REDIS_PORT' => EnvValue::plain((string) $connection->port),
            ...($connection->password === null ? [] : ['REDIS_PASSWORD' => EnvValue::secret($connection->password)]),
            'CACHE_STORE' => EnvValue::plain('redis'),
        ];
    }

    public function bucket(BucketConnection $connection): array
    {
        return [
            'FILESYSTEM_DISK' => EnvValue::plain('s3'),
            'AWS_ACCESS_KEY_ID' => EnvValue::plain($connection->accessKeyId),
            'AWS_SECRET_ACCESS_KEY' => EnvValue::secret($connection->secretAccessKey),
            'AWS_DEFAULT_REGION' => EnvValue::plain($connection->region),
            'AWS_BUCKET' => EnvValue::plain($connection->bucket),
            'AWS_ENDPOINT' => EnvValue::plain($connection->endpoint),
            'AWS_USE_PATH_STYLE_ENDPOINT' => EnvValue::plain($connection->pathStyle ? 'true' : 'false'),
        ];
    }

    public function configLine(DatastoreKind $kind, string $name): ?string
    {
        return "'{$name}' => ['driver' => '".self::DRIVERS[$kind->value]."', 'url' => env('".self::urlKey($name)."')],";
    }

    private static function urlKey(string $name): string
    {
        return strtoupper($name).'_DB_URL';
    }
}
