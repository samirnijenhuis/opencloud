<?php

namespace Tests\Domains\Datastores;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Connection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\SqlConnection;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttachmentEnvTest extends TestCase
{
    use RefreshDatabase;

    private Cluster $cluster;

    private const GENERIC = [
        'AWS_ACCESS_KEY_ID' => ['env-7', false],
        'AWS_BUCKET' => ['env-7', false],
        'AWS_ENDPOINT_URL' => ['http://rustfs-6.oc-datastores.svc.cluster.local:9000', false],
        'AWS_REGION' => ['us-east-1', false],
        'AWS_SECRET_ACCESS_KEY' => ['rustfs-secret', true],
        'DATABASE_URL' => ['postgresql://env_7:p%2Fss@postgres-4.oc-datastores.svc.cluster.local:5432/env_7', true],
        'REDIS_URL' => ['redis://:valkey-pass@valkey-24.oc-datastores.svc.cluster.local:6379', true],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cluster = Cluster::factory()->create();
    }

    /** @return array<string, array{ApplicationType, array<string, array{string, bool}>}> */
    public static function presets(): array
    {
        return [
            'laravel' => [ApplicationType::LARAVEL, [
                'AWS_ACCESS_KEY_ID' => ['env-7', false],
                'AWS_BUCKET' => ['env-7', false],
                'AWS_DEFAULT_REGION' => ['us-east-1', false],
                'AWS_ENDPOINT' => ['http://rustfs-6.oc-datastores.svc.cluster.local:9000', false],
                'AWS_SECRET_ACCESS_KEY' => ['rustfs-secret', true],
                'AWS_USE_PATH_STYLE_ENDPOINT' => ['true', false],
                'CACHE_STORE' => ['redis', false],
                'DB_CONNECTION' => ['pgsql', false],
                'DB_DATABASE' => ['env_7', false],
                'DB_HOST' => ['postgres-4.oc-datastores.svc.cluster.local', false],
                'DB_PASSWORD' => ['p/ss', true],
                'DB_PORT' => ['5432', false],
                'DB_USERNAME' => ['env_7', false],
                'FILESYSTEM_DISK' => ['s3', false],
                'REDIS_CLIENT' => ['phpredis', false],
                'REDIS_HOST' => ['valkey-24.oc-datastores.svc.cluster.local', false],
                'REDIS_PASSWORD' => ['valkey-pass', true],
                'REDIS_PORT' => ['6379', false],
            ]],
            'node' => [ApplicationType::NODE, self::GENERIC],
            'python' => [ApplicationType::PYTHON, self::GENERIC],
            'ruby' => [ApplicationType::RUBY, self::GENERIC],
            'static' => [ApplicationType::STATIC, self::GENERIC],
            'dockerfile' => [ApplicationType::DOCKERFILE, [
                'AWS_ACCESS_KEY_ID' => ['env-7', false],
                'AWS_BUCKET' => ['env-7', false],
                'AWS_DEFAULT_REGION' => ['us-east-1', false],
                'AWS_ENDPOINT' => ['http://rustfs-6.oc-datastores.svc.cluster.local:9000', false],
                'AWS_ENDPOINT_URL' => ['http://rustfs-6.oc-datastores.svc.cluster.local:9000', false],
                'AWS_REGION' => ['us-east-1', false],
                'AWS_SECRET_ACCESS_KEY' => ['rustfs-secret', true],
                'AWS_USE_PATH_STYLE_ENDPOINT' => ['true', false],
                'CACHE_STORE' => ['redis', false],
                'DATABASE_URL' => ['postgresql://env_7:p%2Fss@postgres-4.oc-datastores.svc.cluster.local:5432/env_7', true],
                'DB_CONNECTION' => ['pgsql', false],
                'DB_DATABASE' => ['env_7', false],
                'DB_HOST' => ['postgres-4.oc-datastores.svc.cluster.local', false],
                'DB_PASSWORD' => ['p/ss', true],
                'DB_PORT' => ['5432', false],
                'DB_USERNAME' => ['env_7', false],
                'FILESYSTEM_DISK' => ['s3', false],
                'REDIS_CLIENT' => ['phpredis', false],
                'REDIS_HOST' => ['valkey-24.oc-datastores.svc.cluster.local', false],
                'REDIS_PASSWORD' => ['valkey-pass', true],
                'REDIS_PORT' => ['6379', false],
                'REDIS_URL' => ['redis://:valkey-pass@valkey-24.oc-datastores.svc.cluster.local:6379', true],
            ]],
        ];
    }

    /** @param  array<string, array{string, bool}>  $expected */
    #[DataProvider('presets')]
    public function test_each_preset_writes_the_env_names_of_its_profiles(ApplicationType $type, array $expected): void
    {
        $environment = $this->environment($type);
        $attachments = [
            $this->attachment(21, $this->datastore(4, DatastoreKind::POSTGRES), $environment, 'main', true, 'postgres-4.oc-datastores.svc.cluster.local', 5432, 'env_7', 'env_7', 'p/ss'),
            $this->attachment(24, $this->datastore(5, DatastoreKind::VALKEY), $environment, null, false, null, null, null, null, 'valkey-pass'),
            $this->attachment(25, $this->datastore(6, DatastoreKind::RUSTFS), $environment, 'main', true, null, null, 'env-7', 'env-7', 'rustfs-secret'),
        ];

        foreach ($attachments as $attachment) {
            AttachmentEnv::write($attachment);
        }

        $this->assertSame($expected, EnvVar::query()->where('environment_id', $environment->id)->orderBy('key')->get()
            ->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret]])
            ->all());
    }

    /** A Dockerfile app reads one of the two sets, so a name that both sets give must have one value. */
    public function test_the_two_sets_of_a_dockerfile_app_share_only_the_aws_key_and_bucket_names_with_the_same_values(): void
    {
        $endpoint = 'http://rustfs-6.oc-datastores.svc.cluster.local:9000';
        $shapes = [
            'mysql main' => new SqlConnection(DatastoreKind::MYSQL, 'mysql-3', 3306, 'env_7', 'env_7', 'p/ss', 'main', true),
            'postgres reports' => new SqlConnection(DatastoreKind::POSTGRES, 'postgres-4', 5432, 'env_7_reports', 'env_7_0b7ec688', 'p/ss', 'reports', false),
            'valkey with tls' => new KeyValueConnection('private-valkey.db.ondigitalocean.com', 25061, 'valkey-pass', tls: true),
            'valkey without a password' => new KeyValueConnection('redis.example.com', 6379, null, tls: false),
            'bucket main' => new BucketConnection($endpoint, 'us-east-1', 'env-7', 'env-7', 'rustfs-secret', true, 'main', isDefault: true),
            'bucket uploads' => new BucketConnection($endpoint, 'us-east-1', 'env-7-fb611de4', 'env-7-fb611de4', 'uploads-secret', true, 'uploads', isDefault: false),
        ];

        $this->assertSame([
            'mysql main' => [],
            'postgres reports' => [],
            'valkey with tls' => [],
            'valkey without a password' => [],
            'bucket main' => ['AWS_ACCESS_KEY_ID', 'AWS_BUCKET', 'AWS_SECRET_ACCESS_KEY'],
            'bucket uploads' => ['UPLOADS_AWS_ACCESS_KEY_ID', 'UPLOADS_AWS_BUCKET', 'UPLOADS_AWS_SECRET_ACCESS_KEY'],
        ], array_map(fn (Connection $connection) => $this->sharedNames($connection), $shapes));
    }

    public function test_a_dockerfile_app_shows_the_laravel_config_line_and_a_node_app_needs_none(): void
    {
        $postgres = $this->datastore(4, DatastoreKind::POSTGRES);
        $lines = [];

        foreach ([ApplicationType::DOCKERFILE, ApplicationType::NODE] as $index => $type) {
            $environment = $this->environment($type, $index + 7);
            $reports = $this->attachment($index + 22, $postgres, $environment, 'reports', false, 'postgres', 5432, 'reports', 'reports', 'pass');
            $lines[$type->value] = AttachmentEnv::configLine($reports);
        }

        $this->assertSame(['dockerfile' => "'reports' => ['driver' => 'pgsql', 'url' => env('REPORTS_DB_URL')],", 'node' => null], $lines);
    }

    /**
     * The names that more than one profile of the Dockerfile preset gives. Each such name must have the same value in
     * each profile.
     *
     * @return list<string>
     */
    private function sharedNames(Connection $connection): array
    {
        $seen = [];
        $shared = [];

        foreach (ApplicationType::DOCKERFILE->envProfiles() as $profile) {
            foreach ($connection->envFor($profile) as $key => $value) {
                if (isset($seen[$key])) {
                    $this->assertEquals($seen[$key], $value, "{$key} has two values.");
                    $shared[] = $key;
                }

                $seen[$key] = $value;
            }
        }

        sort($shared);

        return $shared;
    }

    private function environment(ApplicationType $type, int $id = 7): Environment
    {
        return Environment::factory()->for(Application::factory()->for($this->cluster)->create(['type' => $type]))->create(['id' => $id]);
    }

    private function datastore(int $id, DatastoreKind $kind): Datastore
    {
        return Datastore::factory()->for($this->cluster)->create(['id' => $id, 'kind' => $kind]);
    }

    private function attachment(int $id, Datastore $datastore, Environment $environment, ?string $name, bool $isDefault, ?string $host, ?int $port, ?string $database, ?string $username, string $password): DatastoreAttachment
    {
        return DatastoreAttachment::factory()->create([
            'id' => $id,
            'datastore_id' => $datastore->id,
            'environment_id' => $environment->id,
            'name' => $name,
            'is_default' => $isDefault,
            'host' => $host,
            'port' => $port,
            'database_name' => $database,
            'username' => $username,
            'password' => $password,
        ]);
    }
}
