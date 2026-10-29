<?php

namespace App\Domains\Datastores\Env;

use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\SqlConnection;

/**
 * The names that most frameworks outside PHP read: one URL per database and per key-value server, and the standard
 * AWS SDK names for a bucket. These are the names that Laravel Cloud injects for JavaScript, Go, Python, Ruby, and
 * Rust apps. The Node.js, Python, Ruby, static, and Dockerfile presets use it.
 *
 * The profile sets no framework behavior: no cache store and no default disk.
 */
final class GenericProfile implements EnvProfile
{
    private const SCHEMES = ['mysql' => 'mysql', 'postgres' => 'postgresql'];

    /** The default database sets DATABASE_URL. Each other database sets <NAME>_DATABASE_URL. */
    public function database(SqlConnection $connection): array
    {
        $key = $connection->isDefault ? 'DATABASE_URL' : strtoupper($connection->name).'_DATABASE_URL';

        return [$key => EnvValue::secret($connection->url(self::SCHEMES[$connection->kind->value]))];
    }

    public function keyValue(KeyValueConnection $connection): array
    {
        return ['REDIS_URL' => EnvValue::secret($connection->url())];
    }

    /**
     * The AWS SDKs have no common env name for path-style addressing, so the app sets it in code. Each bucket that is
     * not the default sets the same names with the prefix <NAME>_. A public bucket also sets AWS_URL, the name that the
     * Laravel profile uses, so a Dockerfile app gets one value.
     */
    public function bucket(BucketConnection $connection): array
    {
        $prefix = $connection->isDefault ? '' : strtoupper($connection->name).'_';

        return [
            "{$prefix}AWS_BUCKET" => EnvValue::plain($connection->bucket),
            "{$prefix}AWS_ENDPOINT_URL" => EnvValue::plain($connection->endpoint),
            "{$prefix}AWS_REGION" => EnvValue::plain($connection->region),
            "{$prefix}AWS_ACCESS_KEY_ID" => EnvValue::plain($connection->accessKeyId),
            "{$prefix}AWS_SECRET_ACCESS_KEY" => EnvValue::secret($connection->secretAccessKey),
            ...($connection->url === null ? [] : ["{$prefix}AWS_URL" => EnvValue::plain($connection->url)]),
        ];
    }

    /** The app reads <NAME>_DATABASE_URL and the <NAME>_AWS_* values itself, so it needs no config line. */
    public function configLine(DatastoreKind $kind, string $name, bool $public): ?string
    {
        return null;
    }
}
