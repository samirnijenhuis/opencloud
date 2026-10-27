<?php

namespace Tests\Domains\Datastores\Env;

use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Env\EnvValue;
use App\Domains\Datastores\Env\LaravelProfile;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\SqlConnection;
use PHPUnit\Framework\TestCase;

class LaravelProfileTest extends TestCase
{
    public function test_the_default_postgres_database_sets_the_db_values_with_the_pgsql_driver(): void
    {
        $connection = new SqlConnection(DatastoreKind::Postgres, 'pg.internal', 5432, 'shop', 'shop_user', 's3cret', 'main', true);

        $this->assertEquals([
            'DB_CONNECTION' => EnvValue::plain('pgsql'),
            'DB_HOST' => EnvValue::plain('pg.internal'),
            'DB_PORT' => EnvValue::plain('5432'),
            'DB_DATABASE' => EnvValue::plain('shop'),
            'DB_USERNAME' => EnvValue::plain('shop_user'),
            'DB_PASSWORD' => EnvValue::secret('s3cret'),
        ], $connection->envFor(new LaravelProfile));
    }

    public function test_a_named_postgres_database_sets_one_secret_url_with_an_encoded_password(): void
    {
        $connection = new SqlConnection(DatastoreKind::Postgres, 'pg.internal', 5432, 'rep', 'rep_user', 'p/ss+w@rd:1%', 'reports', false);

        $this->assertEquals([
            'REPORTS_DB_URL' => EnvValue::secret('postgres://rep_user:p%2Fss%2Bw%40rd%3A1%25@pg.internal:5432/rep'),
        ], $connection->envFor(new LaravelProfile));
    }

    public function test_a_named_mysql_database_uses_the_mysql_scheme(): void
    {
        $connection = new SqlConnection(DatastoreKind::Mysql, 'my.internal', 3306, 'cache_db', 'u', 'pw', 'cache', false);

        $this->assertEquals([
            'CACHE_DB_URL' => EnvValue::secret('mysql://u:pw@my.internal:3306/cache_db'),
        ], $connection->envFor(new LaravelProfile));
    }

    public function test_valkey_sets_the_redis_values_and_the_cache_store(): void
    {
        $connection = new KeyValueConnection('valkey.internal', 6379, 'vpw', tls: false);

        $this->assertEquals([
            'REDIS_CLIENT' => EnvValue::plain('phpredis'),
            'REDIS_HOST' => EnvValue::plain('valkey.internal'),
            'REDIS_PORT' => EnvValue::plain('6379'),
            'REDIS_PASSWORD' => EnvValue::secret('vpw'),
            'CACHE_STORE' => EnvValue::plain('redis'),
        ], $connection->envFor(new LaravelProfile));
    }

    public function test_a_key_value_server_without_a_password_sets_no_redis_password(): void
    {
        $values = (new KeyValueConnection('valkey.internal', 6379, null, tls: false))->envFor(new LaravelProfile);

        $this->assertSame(['REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'CACHE_STORE'], array_keys($values));
    }

    public function test_a_path_style_bucket_sets_the_s3_disk_and_the_aws_values(): void
    {
        $connection = new BucketConnection('http://rustfs.internal:9000', 'us-east-1', 'env-7', 'env-7', 'bpw', pathStyle: true);

        $this->assertEquals([
            'FILESYSTEM_DISK' => EnvValue::plain('s3'),
            'AWS_ACCESS_KEY_ID' => EnvValue::plain('env-7'),
            'AWS_SECRET_ACCESS_KEY' => EnvValue::secret('bpw'),
            'AWS_DEFAULT_REGION' => EnvValue::plain('us-east-1'),
            'AWS_BUCKET' => EnvValue::plain('env-7'),
            'AWS_ENDPOINT' => EnvValue::plain('http://rustfs.internal:9000'),
            'AWS_USE_PATH_STYLE_ENDPOINT' => EnvValue::plain('true'),
        ], $connection->envFor(new LaravelProfile));
    }

    public function test_a_virtual_host_bucket_turns_path_style_off(): void
    {
        $values = (new BucketConnection('https://s3.example.com', 'eu-west-1', 'b', 'k', 's', pathStyle: false))->envFor(new LaravelProfile);

        $this->assertEquals(EnvValue::plain('false'), $values['AWS_USE_PATH_STYLE_ENDPOINT']);
    }

    public function test_the_config_line_names_the_driver_and_the_url_value(): void
    {
        $profile = new LaravelProfile;

        $this->assertSame("'reports' => ['driver' => 'pgsql', 'url' => env('REPORTS_DB_URL')],", $profile->configLine(DatastoreKind::Postgres, 'reports'));
        $this->assertSame("'cache' => ['driver' => 'mysql', 'url' => env('CACHE_DB_URL')],", $profile->configLine(DatastoreKind::Mysql, 'cache'));
    }
}
