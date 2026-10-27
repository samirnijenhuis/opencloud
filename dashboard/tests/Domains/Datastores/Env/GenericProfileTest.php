<?php

namespace Tests\Domains\Datastores\Env;

use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Env\EnvValue;
use App\Domains\Datastores\Env\GenericProfile;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\SqlConnection;
use PHPUnit\Framework\TestCase;

class GenericProfileTest extends TestCase
{
    public function test_the_default_postgres_database_sets_a_secret_database_url_with_an_encoded_password(): void
    {
        $connection = new SqlConnection(DatastoreKind::Postgres, 'pg.internal', 5432, 'shop', 'shop_user', 'p/ss+w@rd:1%', 'main', true);

        $this->assertEquals([
            'DATABASE_URL' => EnvValue::secret('postgresql://shop_user:p%2Fss%2Bw%40rd%3A1%25@pg.internal:5432/shop'),
        ], $connection->envFor(new GenericProfile));
    }

    public function test_the_default_mysql_database_uses_the_mysql_scheme(): void
    {
        $connection = new SqlConnection(DatastoreKind::Mysql, 'my.internal', 3306, 'shop', 'u', 'pw', 'main', true);

        $this->assertEquals([
            'DATABASE_URL' => EnvValue::secret('mysql://u:pw@my.internal:3306/shop'),
        ], $connection->envFor(new GenericProfile));
    }

    public function test_a_database_that_is_not_the_default_gets_its_name_in_the_key(): void
    {
        $connection = new SqlConnection(DatastoreKind::Postgres, 'pg.internal', 5432, 'rep', 'rep_user', 'pw', 'reports', false);

        $this->assertEquals([
            'REPORTS_DATABASE_URL' => EnvValue::secret('postgresql://rep_user:pw@pg.internal:5432/rep'),
        ], $connection->envFor(new GenericProfile));
    }

    public function test_a_key_value_server_in_the_cluster_sets_a_redis_url(): void
    {
        $connection = new KeyValueConnection('valkey.internal', 6379, 'v/pw', tls: false);

        $this->assertEquals([
            'REDIS_URL' => EnvValue::secret('redis://:v%2Fpw@valkey.internal:6379'),
        ], $connection->envFor(new GenericProfile));
    }

    public function test_a_key_value_server_with_tls_sets_a_rediss_url(): void
    {
        $connection = new KeyValueConnection('cache.example.com', 25061, 'pw', tls: true);

        $this->assertEquals([
            'REDIS_URL' => EnvValue::secret('rediss://:pw@cache.example.com:25061'),
        ], $connection->envFor(new GenericProfile));
    }

    public function test_a_key_value_server_without_a_password_has_no_credentials_in_the_url(): void
    {
        $connection = new KeyValueConnection('valkey.internal', 6379, null, tls: false);

        $this->assertEquals(['REDIS_URL' => EnvValue::secret('redis://valkey.internal:6379')], $connection->envFor(new GenericProfile));
    }

    public function test_a_bucket_sets_the_aws_sdk_names_and_no_framework_values(): void
    {
        $connection = new BucketConnection('http://rustfs.internal:9000', 'us-east-1', 'env-7', 'env-7', 'bpw', pathStyle: true);

        $this->assertEquals([
            'AWS_BUCKET' => EnvValue::plain('env-7'),
            'AWS_ENDPOINT_URL' => EnvValue::plain('http://rustfs.internal:9000'),
            'AWS_REGION' => EnvValue::plain('us-east-1'),
            'AWS_ACCESS_KEY_ID' => EnvValue::plain('env-7'),
            'AWS_SECRET_ACCESS_KEY' => EnvValue::secret('bpw'),
        ], $connection->envFor(new GenericProfile));
    }

    public function test_a_database_needs_no_config_line(): void
    {
        $this->assertNull((new GenericProfile)->configLine(DatastoreKind::Postgres, 'reports'));
    }
}
