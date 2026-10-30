<?php

namespace Tests\Domains\Datastores;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\DetachDatastore;
use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Connection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Datastores\SqlConnection;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\GitHub\Enums\PullRequestAction;
use App\Domains\GitHub\PullRequestEvent;
use App\Domains\Members\Models\User;
use App\Domains\Previews\Actions\OpenPreview;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

/**
 * A resource that the user runs: opencloud stores the typed address and writes the env values, and nothing else runs.
 * FakeKubectl and FakeTofu stay installed in each test, so a call to either one shows up.
 */
class ExternalResourcesTest extends TestCase
{
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private FakeTofu $tofu;

    private Environment $environment;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->kubectl = FakeKubectl::install();
        $this->tofu = FakeTofu::install();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $this->environment = Environment::factory()->for(Application::factory()->for($cluster))->create(['name' => 'production']);
        $this->owner = User::factory()->owner()->create();
    }

    public function test_every_method_of_an_external_provisioner_runs_no_job_no_kubectl_and_no_opentofu(): void
    {
        $cluster = $this->environment->application->cluster;
        $seen = [];

        foreach (Provisioners::table() as ['kind' => $kind, 'source' => $source, 'provisioner' => $provisioner]) {
            if ($source !== DatastoreSource::EXTERNAL) {
                continue;
            }

            $environment = Environment::factory()->for($this->environment->application)->create();
            $name = $kind->family()->isNamed() ? 'main' : null;

            $refusal = $provisioner->refusalOn($cluster);
            $attachment = $provisioner->attach($environment, $name, $name !== null, $this->typed($kind));
            $provisioner->retry($attachment);
            $provisioner->provision($attachment->fresh());
            $provisioned = $attachment->fresh();
            $connection = $provisioner->connection($provisioned);
            $capabilities = $provisioner->capabilities();
            $datastore = $provisioned->datastore;
            $provisioner->detach($provisioned, null, destroyData: true);
            $provisioner->release($datastore, Arr::except($provisioned->getAttributes(), ['password']), destroyData: true);

            $seen[$kind->value] = [
                'refusal' => $refusal,
                'status' => [$provisioned->status->value, $datastore->status->value],
                'cluster' => $datastore->cluster_id,
                'connection' => $connection,
                'label' => $capabilities->label,
                'no dumps' => $capabilities->noDumps,
                'copies buckets' => $capabilities->copiesBuckets,
                'rows kept' => [DatastoreAttachment::query()->whereKey($provisioned->id)->exists(), Datastore::query()->whereKey($datastore->id)->exists()],
            ];
        }

        $this->assertEquals([
            'mysql' => [
                'refusal' => null,
                'status' => ['ready', 'ready'],
                'cluster' => null,
                'connection' => new SqlConnection(DatastoreKind::MYSQL, 'mysql.example.com', 3306, 'shop', 'shop_app', 'p@ss/w0rd+1', 'main', true),
                'label' => 'External MySQL',
                'no dumps' => 'Opencloud does not back up a database that you run yourself.',
                'copies buckets' => false,
                'rows kept' => [false, false],
            ],
            'postgres' => [
                'refusal' => null,
                'status' => ['ready', 'ready'],
                'cluster' => null,
                'connection' => new SqlConnection(DatastoreKind::POSTGRES, 'pg.example.com', 25060, 'reports', 'doadmin', 'pg:secret%', 'main', true),
                'label' => 'External Postgres',
                'no dumps' => 'Opencloud does not back up a database that you run yourself.',
                'copies buckets' => false,
                'rows kept' => [false, false],
            ],
            'valkey' => [
                'refusal' => null,
                'status' => ['ready', 'ready'],
                'cluster' => null,
                'connection' => new KeyValueConnection('redis.example.com', 6380, 'redis-secret', tls: true),
                'label' => 'External Redis or Valkey',
                'no dumps' => 'Opencloud does not back up a key-value store that you run yourself.',
                'copies buckets' => false,
                'rows kept' => [false, false],
            ],
            'rustfs' => [
                'refusal' => null,
                'status' => ['ready', 'ready'],
                'cluster' => null,
                'connection' => new BucketConnection('https://fsn1.your-objectstorage.com', 'fsn1', 'shop-uploads', 'AKIAEXTERNAL', 'bucket-secret/key', false, 'main', isDefault: true),
                'label' => 'External S3 bucket',
                'no dumps' => 'Opencloud does not copy a bucket that you run yourself.',
                'copies buckets' => false,
                'rows kept' => [false, false],
            ],
        ], $seen);
        $this->assertSame([], Queue::pushedJobs());
        $this->assertSame([], $this->kubectl->calls);
        $this->assertSame([], $this->tofu->ran);
    }

    public function test_an_external_provisioner_refuses_an_address_of_another_family(): void
    {
        $provisioner = Provisioners::for(DatastoreKind::MYSQL, DatastoreSource::EXTERNAL);

        foreach ([null, $this->typed(DatastoreKind::POSTGRES), $this->typed(DatastoreKind::VALKEY)] as $given) {
            try {
                $provisioner->attach($this->environment, 'main', true, $given);
                $this->fail('The attach took an address of another kind.');
            } catch (LogicException $e) {
                $this->assertSame('The external source needs the mysql address that the user typed.', $e->getMessage());
            }
        }

        $this->assertSame(0, Datastore::query()->count());
    }

    public function test_external_databases_are_ready_at_once_with_the_laravel_env_and_detach_removes_only_the_rows(): void
    {
        $attach = app(AttachDatastore::class);
        $main = $attach->execute($this->environment, $this->owner, DatastoreKind::MYSQL, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::MYSQL));
        $reports = $attach->execute($this->environment, $this->owner, DatastoreKind::POSTGRES, DatastoreSource::EXTERNAL, 'reports', $this->typed(DatastoreKind::POSTGRES));

        $this->assertSame(
            [['main', true, 'ready', null], ['reports', false, 'ready', null]],
            [[$main->name, $main->is_default, $main->status->value, $main->datastore->cluster_id], [$reports->name, $reports->is_default, $reports->status->value, $reports->datastore->cluster_id]],
        );
        $this->assertSame([
            ['DB_CONNECTION', 'mysql', false, $main->id],
            ['DB_DATABASE', 'shop', false, $main->id],
            ['DB_HOST', 'mysql.example.com', false, $main->id],
            ['DB_PASSWORD', 'p@ss/w0rd+1', true, $main->id],
            ['DB_PORT', '3306', false, $main->id],
            ['DB_USERNAME', 'shop_app', false, $main->id],
            ['REPORTS_DB_URL', 'postgres://doadmin:pg%3Asecret%25@pg.example.com:25060/reports', true, $reports->id],
        ], $this->env());

        // Attaching again never drops the new address: the name is taken, so the action refuses.
        try {
            $attach->execute($this->environment, $this->owner, DatastoreKind::POSTGRES, DatastoreSource::EXTERNAL, 'reports', $this->typed(DatastoreKind::POSTGRES));
            $this->fail('A second database took the name reports.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a database with the name reports.', $e->getMessage());
        }

        $detach = app(DetachDatastore::class);

        try {
            $detach->execute($main, $this->owner);
            $this->fail('The default went while another database exists.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Make another database the default first.', $e->getMessage());
        }

        $detach->execute($reports, $this->owner);
        $detach->execute($main, $this->owner);

        $this->assertSame([0, 0, []], [DatastoreAttachment::query()->count(), Datastore::query()->count(), $this->env()]);
        $this->assertSame(
            [['datastore.attached', 'mysql', 'external'], ['datastore.attached', 'postgres', 'external'], ['datastore.detached', 'postgres', 'external'], ['datastore.detached', 'mysql', 'external']],
            AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->context['kind'], $event->context['source']])->all(),
        );
        $this->assertNothingRan();
    }

    public function test_an_external_key_value_store_is_ready_at_once_with_the_laravel_env_and_detach_removes_only_the_rows(): void
    {
        $attach = app(AttachDatastore::class);
        $store = $attach->execute($this->environment, $this->owner, DatastoreKind::VALKEY, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::VALKEY));

        $this->assertSame([null, 'ready', true], [$store->name, $store->status->value, $store->tls]);
        $this->assertSame([
            ['CACHE_STORE', 'redis', false, $store->id],
            ['REDIS_CLIENT', 'phpredis', false, $store->id],
            ['REDIS_HOST', 'tls://redis.example.com', false, $store->id],
            ['REDIS_PASSWORD', 'redis-secret', true, $store->id],
            ['REDIS_PORT', '6380', false, $store->id],
        ], $this->env());

        // A second typed address is refused, so it never goes unused. An attach without an address finds the store.
        try {
            $attach->execute($this->environment, $this->owner, DatastoreKind::VALKEY, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::VALKEY));
            $this->fail('A second key-value store took the place of the first.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a valkey datastore.', $e->getMessage());
        }
        $this->assertTrue($attach->execute($this->environment, $this->owner, DatastoreKind::VALKEY)->is($store));
        $this->assertSame(1, DatastoreAttachment::query()->count());

        app(DetachDatastore::class)->execute($store, $this->owner);

        $this->assertSame([0, 0, []], [DatastoreAttachment::query()->count(), Datastore::query()->count(), $this->env()]);
        $this->assertNothingRan();
    }

    public function test_two_external_buckets_are_ready_at_once_with_the_laravel_env_and_detach_removes_only_the_rows(): void
    {
        $attach = app(AttachDatastore::class);
        $bucket = $attach->execute($this->environment, $this->owner, DatastoreKind::RUSTFS, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::RUSTFS));

        $this->assertSame(
            ['https://fsn1.your-objectstorage.com', 'fsn1', 'shop-uploads', 'AKIAEXTERNAL', false, 'ready'],
            [$bucket->host, $bucket->region, $bucket->database_name, $bucket->username, $bucket->path_style, $bucket->status->value],
        );
        $this->assertSame([
            ['AWS_ACCESS_KEY_ID', 'AKIAEXTERNAL', false, $bucket->id],
            ['AWS_BUCKET', 'shop-uploads', false, $bucket->id],
            ['AWS_DEFAULT_REGION', 'fsn1', false, $bucket->id],
            ['AWS_ENDPOINT', 'https://fsn1.your-objectstorage.com', false, $bucket->id],
            ['AWS_SECRET_ACCESS_KEY', 'bucket-secret/key', true, $bucket->id],
            ['AWS_USE_PATH_STYLE_ENDPOINT', 'false', false, $bucket->id],
            ['FILESYSTEM_DISK', 's3', false, $bucket->id],
        ], $this->env());

        try {
            $attach->execute($this->environment, $this->owner, DatastoreKind::RUSTFS, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::RUSTFS));
            $this->fail('A second bucket took the place of the first.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a bucket. Give the new bucket a name.', $e->getMessage());
        }

        $uploads = $attach->execute($this->environment, $this->owner, DatastoreKind::RUSTFS, DatastoreSource::EXTERNAL, 'uploads', $this->typed(DatastoreKind::RUSTFS));

        $this->assertSame(['main', true, 'uploads', false], [$bucket->name, $bucket->is_default, $uploads->name, $uploads->is_default]);
        $this->assertNotSame($bucket->datastore_id, $uploads->datastore_id);
        $this->assertSame([
            ['UPLOADS_AWS_ACCESS_KEY_ID', 'AKIAEXTERNAL', false, $uploads->id],
            ['UPLOADS_AWS_BUCKET', 'shop-uploads', false, $uploads->id],
            ['UPLOADS_AWS_DEFAULT_REGION', 'fsn1', false, $uploads->id],
            ['UPLOADS_AWS_ENDPOINT', 'https://fsn1.your-objectstorage.com', false, $uploads->id],
            ['UPLOADS_AWS_SECRET_ACCESS_KEY', 'bucket-secret/key', true, $uploads->id],
            ['UPLOADS_AWS_USE_PATH_STYLE_ENDPOINT', 'false', false, $uploads->id],
        ], array_values(array_filter($this->env(), fn (array $row) => $row[3] === $uploads->id)));

        app(DetachDatastore::class)->execute($uploads, $this->owner);
        app(DetachDatastore::class)->execute($bucket, $this->owner);

        $this->assertSame([0, 0, []], [DatastoreAttachment::query()->count(), Datastore::query()->count(), $this->env()]);
        $this->assertNothingRan();
    }

    public function test_a_preview_gets_local_resources_for_the_external_resources_of_production(): void
    {
        $application = $this->environment->application;
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        $attach = app(AttachDatastore::class);
        $attach->execute($this->environment, $this->owner, DatastoreKind::POSTGRES, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::POSTGRES));
        $attach->execute($this->environment, $this->owner, DatastoreKind::MYSQL, DatastoreSource::EXTERNAL, 'reports', $this->typed(DatastoreKind::MYSQL));
        $attach->execute($this->environment, $this->owner, DatastoreKind::VALKEY, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::VALKEY));
        $attach->execute($this->environment, $this->owner, DatastoreKind::RUSTFS, DatastoreSource::EXTERNAL, null, $this->typed(DatastoreKind::RUSTFS));

        $preview = app(OpenPreview::class)->execute($application, new PullRequestEvent(PullRequestAction::OPENED, $application->repository, 12, 'feature/login', str_repeat('1', 40), fromFork: false, labels: [], changedLabel: null));

        $this->assertSame([
            ['postgres', 'standard', 'main', true, 'local'],
            ['rustfs', 'standard', 'main', true, 'local'],
            ['mysql', 'standard', 'reports', false, 'local'],
            ['valkey', 'standard', null, false, 'local'],
        ], $preview->datastoreAttachments()->with('datastore.cluster')->orderBy('id')->get()
            ->map(fn (DatastoreAttachment $a) => [$a->datastore->kind->value, $a->datastore->source->value, $a->name, $a->is_default, $a->datastore->cluster?->name])->all());
        $this->assertSame(
            ['postgres-', 'env_'.$preview->id],
            [substr($preview->envVars()->where('key', 'DB_HOST')->sole()->value, 0, 9), $preview->envVars()->where('key', 'DB_DATABASE')->sole()->value],
        );
        $this->assertSame([], $this->tofu->ran);
    }

    private function typed(DatastoreKind $kind): Connection
    {
        return match ($kind) {
            DatastoreKind::MYSQL => new SqlConnection($kind, 'mysql.example.com', 3306, 'shop', 'shop_app', 'p@ss/w0rd+1', 'main', isDefault: false),
            DatastoreKind::POSTGRES => new SqlConnection($kind, 'pg.example.com', 25060, 'reports', 'doadmin', 'pg:secret%', 'reports', isDefault: false),
            DatastoreKind::VALKEY => new KeyValueConnection('redis.example.com', 6380, 'redis-secret', tls: true),
            DatastoreKind::RUSTFS => new BucketConnection('https://fsn1.your-objectstorage.com', 'fsn1', 'shop-uploads', 'AKIAEXTERNAL', 'bucket-secret/key', false, 'main', isDefault: false),
        };
    }

    /** @return list<array{0: string, 1: string, 2: bool, 3: int}> key, value, secret, and attachment of each datastore value */
    private function env(): array
    {
        return EnvVar::query()->where('environment_id', $this->environment->id)->where('source', EnvVarSource::DATASTORE)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => [$var->key, $var->value, $var->is_secret, $var->datastore_attachment_id])
            ->all();
    }

    private function assertNothingRan(): void
    {
        $this->assertSame([[], [], []], [Queue::pushedJobs(), $this->kubectl->calls, $this->tofu->ran]);
    }
}
