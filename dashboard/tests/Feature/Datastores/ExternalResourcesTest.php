<?php

namespace Tests\Feature\Datastores;

use App\Actions\AttachDatastore;
use App\Actions\DetachDatastore;
use App\Datastores\BucketConnection;
use App\Datastores\Connection;
use App\Datastores\KeyValueConnection;
use App\Datastores\Provisioners;
use App\Datastores\SqlConnection;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarSource;
use App\GitHub\PullRequestAction;
use App\GitHub\PullRequestEvent;
use App\Kube\FakeKubectl;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use App\Previews\OpenPreview;
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
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $this->environment = Environment::factory()->for(Application::factory()->for($cluster))->create(['name' => 'production']);
        $this->owner = User::factory()->owner()->create();
    }

    public function test_every_method_of_an_external_provisioner_runs_no_job_no_kubectl_and_no_opentofu(): void
    {
        $cluster = $this->environment->application->cluster;
        $seen = [];

        foreach (Provisioners::table() as ['kind' => $kind, 'source' => $source, 'provisioner' => $provisioner]) {
            if ($source !== DatastoreSource::External) {
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
                'connection' => new SqlConnection(DatastoreKind::Mysql, 'mysql.example.com', 3306, 'shop', 'shop_app', 'p@ss/w0rd+1', 'main', true),
                'label' => 'External MySQL',
                'no dumps' => 'Opencloud does not back up a database that you run yourself.',
                'copies buckets' => false,
                'rows kept' => [false, false],
            ],
            'postgres' => [
                'refusal' => null,
                'status' => ['ready', 'ready'],
                'cluster' => null,
                'connection' => new SqlConnection(DatastoreKind::Postgres, 'pg.example.com', 25060, 'reports', 'doadmin', 'pg:secret%', 'main', true),
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
                'connection' => new BucketConnection('https://fsn1.your-objectstorage.com', 'fsn1', 'shop-uploads', 'AKIAEXTERNAL', 'bucket-secret/key', pathStyle: false),
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
        $provisioner = Provisioners::for(DatastoreKind::Mysql, DatastoreSource::External);

        foreach ([null, $this->typed(DatastoreKind::Postgres), $this->typed(DatastoreKind::Valkey)] as $given) {
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
        $main = $attach->handle($this->environment, $this->owner, DatastoreKind::Mysql, DatastoreSource::External, null, $this->typed(DatastoreKind::Mysql));
        $reports = $attach->handle($this->environment, $this->owner, DatastoreKind::Postgres, DatastoreSource::External, 'reports', $this->typed(DatastoreKind::Postgres));

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
            $attach->handle($this->environment, $this->owner, DatastoreKind::Postgres, DatastoreSource::External, 'reports', $this->typed(DatastoreKind::Postgres));
            $this->fail('A second database took the name reports.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a database with the name reports.', $e->getMessage());
        }

        $detach = app(DetachDatastore::class);

        try {
            $detach->handle($main, $this->owner);
            $this->fail('The default went while another database exists.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Make another database the default first.', $e->getMessage());
        }

        $detach->handle($reports, $this->owner);
        $detach->handle($main, $this->owner);

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
        $store = $attach->handle($this->environment, $this->owner, DatastoreKind::Valkey, DatastoreSource::External, null, $this->typed(DatastoreKind::Valkey));

        $this->assertSame([null, 'ready', true], [$store->name, $store->status->value, $store->tls]);
        $this->assertSame([
            ['CACHE_STORE', 'redis', false, $store->id],
            ['REDIS_CLIENT', 'phpredis', false, $store->id],
            ['REDIS_HOST', 'redis.example.com', false, $store->id],
            ['REDIS_PASSWORD', 'redis-secret', true, $store->id],
            ['REDIS_PORT', '6380', false, $store->id],
        ], $this->env());

        // A second typed address is refused, so it never goes unused. An attach without an address finds the store.
        try {
            $attach->handle($this->environment, $this->owner, DatastoreKind::Valkey, DatastoreSource::External, null, $this->typed(DatastoreKind::Valkey));
            $this->fail('A second key-value store took the place of the first.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a valkey datastore.', $e->getMessage());
        }
        $this->assertTrue($attach->handle($this->environment, $this->owner, DatastoreKind::Valkey)->is($store));
        $this->assertSame(1, DatastoreAttachment::query()->count());

        app(DetachDatastore::class)->handle($store, $this->owner);

        $this->assertSame([0, 0, []], [DatastoreAttachment::query()->count(), Datastore::query()->count(), $this->env()]);
        $this->assertNothingRan();
    }

    public function test_an_external_bucket_is_ready_at_once_with_the_laravel_env_and_detach_removes_only_the_rows(): void
    {
        $attach = app(AttachDatastore::class);
        $bucket = $attach->handle($this->environment, $this->owner, DatastoreKind::Rustfs, DatastoreSource::External, null, $this->typed(DatastoreKind::Rustfs));

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
            $attach->handle($this->environment, $this->owner, DatastoreKind::Rustfs, DatastoreSource::External, null, $this->typed(DatastoreKind::Rustfs));
            $this->fail('A second bucket took the place of the first.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This environment already has a rustfs datastore.', $e->getMessage());
        }

        app(DetachDatastore::class)->handle($bucket, $this->owner);

        $this->assertSame([0, 0, []], [DatastoreAttachment::query()->count(), Datastore::query()->count(), $this->env()]);
        $this->assertNothingRan();
    }

    public function test_a_preview_gets_local_resources_for_the_external_resources_of_production(): void
    {
        $application = $this->environment->application;
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PreviewTemplate]);
        $attach = app(AttachDatastore::class);
        $attach->handle($this->environment, $this->owner, DatastoreKind::Postgres, DatastoreSource::External, null, $this->typed(DatastoreKind::Postgres));
        $attach->handle($this->environment, $this->owner, DatastoreKind::Mysql, DatastoreSource::External, 'reports', $this->typed(DatastoreKind::Mysql));
        $attach->handle($this->environment, $this->owner, DatastoreKind::Valkey, DatastoreSource::External, null, $this->typed(DatastoreKind::Valkey));
        $attach->handle($this->environment, $this->owner, DatastoreKind::Rustfs, DatastoreSource::External, null, $this->typed(DatastoreKind::Rustfs));

        $preview = app(OpenPreview::class)->handle($application, new PullRequestEvent(PullRequestAction::Opened, $application->repository, 12, 'feature/login', str_repeat('1', 40), fromFork: false));

        $this->assertSame([
            ['postgres', 'standard', 'main', true, 'local'],
            ['mysql', 'standard', 'reports', false, 'local'],
            ['valkey', 'standard', null, false, 'local'],
            ['rustfs', 'standard', null, false, 'local'],
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
            DatastoreKind::Mysql => new SqlConnection($kind, 'mysql.example.com', 3306, 'shop', 'shop_app', 'p@ss/w0rd+1', 'main', isDefault: false),
            DatastoreKind::Postgres => new SqlConnection($kind, 'pg.example.com', 25060, 'reports', 'doadmin', 'pg:secret%', 'reports', isDefault: false),
            DatastoreKind::Valkey => new KeyValueConnection('redis.example.com', 6380, 'redis-secret', tls: true),
            DatastoreKind::Rustfs => new BucketConnection('https://fsn1.your-objectstorage.com', 'fsn1', 'shop-uploads', 'AKIAEXTERNAL', 'bucket-secret/key', pathStyle: false),
        };
    }

    /** @return list<array{0: string, 1: string, 2: bool, 3: int}> key, value, secret, and attachment of each datastore value */
    private function env(): array
    {
        return EnvVar::query()->where('environment_id', $this->environment->id)->where('source', EnvVarSource::Datastore)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => [$var->key, $var->value, $var->is_secret, $var->datastore_attachment_id])
            ->all();
    }

    private function assertNothingRan(): void
    {
        $this->assertSame([[], [], []], [Queue::pushedJobs(), $this->kubectl->calls, $this->tofu->ran]);
    }
}
