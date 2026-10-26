<?php

namespace App\Datastores\Env;

use App\Datastores\BucketConnection;
use App\Datastores\KeyValueConnection;
use App\Datastores\SqlConnection;
use App\Enums\DatastoreKind;

/**
 * The names that most frameworks outside PHP read: one URL per database and per key-value server, and the standard
 * AWS SDK names for a bucket. These are the names that Laravel Cloud injects for JavaScript, Go, Python, Ruby, and
 * Rust apps. An application with env_profile generic selects it.
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

    /** The AWS SDKs have no common env name for path-style addressing, so the app sets it in code. */
    public function bucket(BucketConnection $connection): array
    {
        return [
            'AWS_BUCKET' => EnvValue::plain($connection->bucket),
            'AWS_ENDPOINT_URL' => EnvValue::plain($connection->endpoint),
            'AWS_REGION' => EnvValue::plain($connection->region),
            'AWS_ACCESS_KEY_ID' => EnvValue::plain($connection->accessKeyId),
            'AWS_SECRET_ACCESS_KEY' => EnvValue::secret($connection->secretAccessKey),
        ];
    }

    /** The app reads <NAME>_DATABASE_URL itself, so it needs no config line. */
    public function configLine(DatastoreKind $kind, string $name): ?string
    {
        return null;
    }
}
