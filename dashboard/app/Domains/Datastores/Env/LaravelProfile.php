<?php

namespace App\Domains\Datastores\Env;

use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\SqlConnection;

/**
 * The names that a stock Laravel app reads without config. The default database sets the DB_* values, so a Laravel
 * app needs no config. Each other database sets one <NAME>_DB_URL value, and the app adds one line to
 * config/database.php for it. The default bucket sets the AWS_* values of the stock s3 disk. Each other bucket sets
 * the same values with the prefix <NAME>_, and the app adds one disk line to config/filesystems.php for it.
 *
 * An attach also changes app behavior: Valkey sets CACHE_STORE=redis and the default bucket sets FILESYSTEM_DISK=s3,
 * like Laravel Cloud does for a PHP app. Those values belong to this profile, not to the resource.
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
            // phpredis opens a TLS connection for a host with tls://, and the stock Laravel config has no scheme value.
            'REDIS_HOST' => EnvValue::plain($connection->tls ? "tls://{$connection->host}" : $connection->host),
            'REDIS_PORT' => EnvValue::plain((string) $connection->port),
            ...($connection->password === null ? [] : ['REDIS_PASSWORD' => EnvValue::secret($connection->password)]),
            'CACHE_STORE' => EnvValue::plain('redis'),
        ];
    }

    public function bucket(BucketConnection $connection): array
    {
        $prefix = $connection->isDefault ? '' : self::prefix($connection->name);

        return [
            ...($connection->isDefault ? ['FILESYSTEM_DISK' => EnvValue::plain('s3')] : []),
            "{$prefix}AWS_ACCESS_KEY_ID" => EnvValue::plain($connection->accessKeyId),
            "{$prefix}AWS_SECRET_ACCESS_KEY" => EnvValue::secret($connection->secretAccessKey),
            "{$prefix}AWS_DEFAULT_REGION" => EnvValue::plain($connection->region),
            "{$prefix}AWS_BUCKET" => EnvValue::plain($connection->bucket),
            "{$prefix}AWS_ENDPOINT" => EnvValue::plain($connection->endpoint),
            "{$prefix}AWS_USE_PATH_STYLE_ENDPOINT" => EnvValue::plain($connection->pathStyle ? 'true' : 'false'),
        ];
    }

    /** A connection in config/database.php for a database, and a disk in config/filesystems.php for a bucket. */
    public function configLine(DatastoreKind $kind, string $name): ?string
    {
        $prefix = self::prefix($name);

        return match ($kind->family()) {
            ResourceFamily::DATABASE => "'{$name}' => ['driver' => '".self::DRIVERS[$kind->value]."', 'url' => env('".self::urlKey($name)."')],",
            ResourceFamily::BUCKET => "'{$name}' => ['driver' => 's3', 'key' => env('{$prefix}AWS_ACCESS_KEY_ID'), 'secret' => env('{$prefix}AWS_SECRET_ACCESS_KEY'), "
                ."'region' => env('{$prefix}AWS_DEFAULT_REGION'), 'bucket' => env('{$prefix}AWS_BUCKET'), 'endpoint' => env('{$prefix}AWS_ENDPOINT'), "
                ."'use_path_style_endpoint' => env('{$prefix}AWS_USE_PATH_STYLE_ENDPOINT', false)],",
            ResourceFamily::KEY_VALUE => null,
        };
    }

    private static function urlKey(string $name): string
    {
        return strtoupper($name).'_DB_URL';
    }

    private static function prefix(string $name): string
    {
        return strtoupper($name).'_';
    }
}
