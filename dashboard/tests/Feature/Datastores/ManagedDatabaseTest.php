<?php

namespace Tests\Feature\Datastores;

use App\Actions\AttachDatastore;
use App\Actions\DetachDatastore;
use App\Actions\SetDefaultDatabase;
use App\Clusters\ClusterStatus;
use App\Datastores\DatastoreStatus;
use App\Datastores\Provisioners;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\EnvVarSource;
use App\Jobs\BackupDatastore;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\ProvisionManagedDatabase;
use App\Jobs\TearDownManagedDatabase;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Tofu\Runner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

class ManagedDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_FAKE_TOKEN_managed_0123456789';

    private const OUTPUTS = [
        'host' => 'private-ams3-prod-mysql-1-do-user-1.db.ondigitalocean.com',
        'port' => 25060,
        'database' => 'env_1',
        'user' => 'env_1',
        'password' => 'DO_GENERATED_PASSWORD_SECRET',
    ];

    private string $data;

    private FakeTofu $tofu;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->data = sys_get_temp_dir().'/opencloud-managed-db-'.getmypid();
        config(['opencloud.data_path' => $this->data]);
        $this->tofu = FakeTofu::install();
        $this->tofu->outputs = self::OUTPUTS;
        ProviderCredential::factory()->create(['provider' => 'digitalocean', 'token' => self::TOKEN]);
        $this->cluster = Cluster::factory()->create([
            'name' => 'ams3-prod',
            'kind' => ClusterKind::Created,
            'provider' => 'digitalocean',
            'status' => ClusterStatus::Ready,
            'region' => 'ams3',
            'provider_cluster_id' => 'c0ffee00-1111',
            'provider_vpc_id' => 'vpc-2222',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->data);

        parent::tearDown();
    }

    public function test_attach_creates_a_managed_datastore_for_the_environment_and_queues_the_apply(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();

        $attachment = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Mysql, DatastoreSource::Managed);

        $datastore = $attachment->datastore;
        $this->assertSame([DatastoreSource::Managed, DatastoreKind::Mysql, '8', $this->cluster->id], [$datastore->source, $datastore->kind, $datastore->version, $datastore->cluster_id]);
        $this->assertSame(["env_{$environment->id}", "env_{$environment->id}", null], [$attachment->database_name, $attachment->username, $attachment->password]);
        $this->assertSame(0, $environment->envVars()->count());
        $job = Queue::pushed(ProvisionManagedDatabase::class)->sole();
        $this->assertSame([$attachment->id, 'builds'], [$job->attachment->id, $job->queue]);
        Queue::assertNotPushed(ProvisionDatastoreAttachment::class);
        $this->assertSame(['main', true], [$attachment->name, $attachment->is_default]);
        $this->assertSame(['environment_id' => $environment->id, 'kind' => 'mysql', 'name' => 'main', 'source' => 'managed'], AuditEvent::query()->sole()->context);
    }

    public function test_a_key_without_the_database_right_does_not_offer_a_managed_database(): void
    {
        $this->assertSame([DatastoreKind::Postgres], Provisioners::offered(DatastoreSource::Managed, $this->cluster));

        ProviderCredential::query()->update(['missing_rights' => ['vpc']]);
        $this->assertSame([DatastoreKind::Postgres], Provisioners::offered(DatastoreSource::Managed, $this->cluster));

        ProviderCredential::query()->update(['missing_rights' => ['vpc', 'database']]);
        $this->assertSame([], Provisioners::offered(DatastoreSource::Managed, $this->cluster));

        $this->assertRefused($this->environment(), DatastoreKind::Postgres, 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.');
    }

    public function test_the_apply_writes_the_same_laravel_env_values_as_the_standard_engine(): void
    {
        $environment = $this->environment();
        $attachment = app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Mysql, DatastoreSource::Managed);

        (new ProvisionManagedDatabase($attachment))->handle(app(Runner::class));

        $datastore = $attachment->datastore;
        $this->assertSame("database-{$datastore->id}", basename($this->tofu->ran[0]['path']));
        $this->assertSame([
            'name' => "ams3-prod-mysql-{$datastore->id}",
            'engine' => 'mysql',
            'engine_version' => '8',
            'size' => 'db-s-1vcpu-1gb',
            'region' => 'ams3',
            'vpc_id' => 'vpc-2222',
            'kubernetes_cluster_id' => 'c0ffee00-1111',
            'database' => "env_{$environment->id}",
            'user' => "env_{$environment->id}",
        ], $this->tofu->ran[1]['variables']);
        $this->assertSame(self::TOKEN, $this->tofu->ran[1]['env']['DIGITALOCEAN_TOKEN']);

        $this->assertSame([
            'DB_CONNECTION' => ['mysql', false],
            'DB_DATABASE' => ['env_1', false],
            'DB_HOST' => ['private-ams3-prod-mysql-1-do-user-1.db.ondigitalocean.com', false],
            'DB_PASSWORD' => ['DO_GENERATED_PASSWORD_SECRET', true],
            'DB_PORT' => ['25060', false],
            'DB_USERNAME' => ['env_1', false],
        ], $this->envOf($environment));
        $this->assertSame([DatastoreStatus::Ready, DatastoreStatus::Ready], [$attachment->fresh()->status, $datastore->fresh()->status]);
        $this->assertSame('DO_GENERATED_PASSWORD_SECRET', $attachment->fresh()->password);
    }

    public function test_a_managed_database_next_to_the_default_gets_its_url_value_after_the_apply(): void
    {
        $environment = $this->environment();
        $this->tofu->outputs = [...self::OUTPUTS, 'password' => 'DO/pass+word'];
        app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Postgres, DatastoreSource::Managed);
        $reports = app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Postgres, DatastoreSource::Managed, 'reports');

        $this->assertSame(['reports', false, "env_{$environment->id}_reports", "env_{$environment->id}_0b7ec688"], [$reports->name, $reports->is_default, $reports->database_name, $reports->username]);
        $this->assertSame([], $this->envOf($environment));

        (new ProvisionManagedDatabase($reports))->handle(app(Runner::class));

        $this->assertSame(['database' => "env_{$environment->id}_reports", 'user' => "env_{$environment->id}_0b7ec688"], array_intersect_key($this->tofu->ran[1]['variables'], array_flip(['database', 'user'])));
        $this->assertSame([
            'REPORTS_DB_URL' => ["postgres://env_{$environment->id}_0b7ec688:DO%2Fpass%2Bword@private-ams3-prod-mysql-1-do-user-1.db.ondigitalocean.com:25060/env_{$environment->id}_reports", true],
        ], $this->envOf($environment));
        $this->assertSame(['private-ams3-prod-mysql-1-do-user-1.db.ondigitalocean.com', 25060], [$reports->fresh()->host, $reports->fresh()->port]);
    }

    public function test_a_managed_database_that_becomes_the_default_during_its_apply_writes_the_db_values(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $main = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Postgres);
        $reports = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Postgres, DatastoreSource::Managed, 'reports');
        $job = new ProvisionManagedDatabase($reports->fresh());

        app(SetDefaultDatabase::class)->handle($reports, $owner);
        $this->assertSame(['MAIN_DB_URL'], array_keys($this->envOf($environment)));

        $job->handle(app(Runner::class));

        $id = $environment->id;
        $this->assertSame([
            'DB_CONNECTION' => ['pgsql', false],
            'DB_DATABASE' => ["env_{$id}_reports", false],
            'DB_HOST' => ['private-ams3-prod-mysql-1-do-user-1.db.ondigitalocean.com', false],
            'DB_PASSWORD' => ['DO_GENERATED_PASSWORD_SECRET', true],
            'DB_PORT' => ['25060', false],
            'DB_USERNAME' => ["env_{$id}_0b7ec688", false],
            'MAIN_DB_URL' => ["postgres://env_{$id}:{$main->password}@postgres-{$main->datastore_id}.oc-datastores.svc.cluster.local:5432/env_{$id}", true],
        ], $this->envOf($environment));
    }

    public function test_destroying_the_managed_default_frees_its_name_and_the_default_at_once(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $managed = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Postgres, DatastoreSource::Managed);
        (new ProvisionManagedDatabase($managed))->handle(app(Runner::class));

        app(DetachDatastore::class)->handle($managed->fresh(), $owner);
        $standard = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Postgres);

        $this->assertSame([null, false, DatastoreStatus::Destroying], [$managed->fresh()->name, $managed->fresh()->is_default, $managed->datastore->fresh()->status]);
        $this->assertSame(['main', true], [$standard->name, $standard->is_default]);
    }

    public function test_postgres_uses_the_pg_engine_and_the_pgsql_connection(): void
    {
        $environment = $this->environment();
        $attachment = app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Postgres, DatastoreSource::Managed);

        (new ProvisionManagedDatabase($attachment))->handle(app(Runner::class));

        $this->assertSame(['pg', '17'], [$this->tofu->ran[1]['variables']['engine'], $this->tofu->ran[1]['variables']['engine_version']]);
        $this->assertSame(['pgsql', false], $this->envOf($environment)['DB_CONNECTION']);
    }

    public function test_a_failed_apply_fails_both_records_and_attach_again_retries_with_opentofu(): void
    {
        $environment = $this->environment();
        $attachment = app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Mysql, DatastoreSource::Managed);
        $this->tofu->failOn = 'apply';
        $this->tofu->error = 'Error: Error creating database cluster: 422 You have reached the database cluster limit';

        (new ProvisionManagedDatabase($attachment))->handle(app(Runner::class));

        $expected = 'tofu apply failed. Error: Error creating database cluster: 422 You have reached the database cluster limit';
        $this->assertSame([DatastoreStatus::Failed, $expected], [$attachment->fresh()->status, $attachment->fresh()->error]);
        $this->assertSame([DatastoreStatus::Failed, $expected], [$attachment->datastore->fresh()->status, $attachment->datastore->fresh()->error]);
        $this->assertSame(0, $environment->envVars()->count());

        $again = app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Mysql, name: 'main');

        $this->assertTrue($again->is($attachment));
        $this->assertCount(2, Queue::pushed(ProvisionManagedDatabase::class));
        Queue::assertNotPushed(ProvisionDatastoreAttachment::class);
    }

    public function test_managed_is_refused_for_other_kinds_other_clusters_and_without_a_key(): void
    {
        $this->assertRefused($this->environment(), DatastoreKind::Valkey, 'DigitalOcean manages MySQL and Postgres only.');

        $connected = Application::factory()->for(Cluster::factory()->create(['status' => ClusterStatus::Ready]))->create();
        $this->assertRefused(Environment::factory()->for($connected)->create(), DatastoreKind::Mysql, 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.');

        ProviderCredential::query()->delete();
        $this->assertRefused($this->environment(), DatastoreKind::Mysql, 'A managed database needs a cluster that opencloud created on DigitalOcean and a DigitalOcean key.');
    }

    public function test_detach_removes_the_env_values_now_and_destroys_the_database_with_opentofu(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $attachment = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Mysql, DatastoreSource::Managed);
        (new ProvisionManagedDatabase($attachment))->handle(app(Runner::class));
        $datastore = $attachment->datastore;

        app(DetachDatastore::class)->handle($attachment->fresh(), $owner);

        $this->assertSame(0, $environment->envVars()->count());
        $this->assertSame(DatastoreStatus::Destroying, $datastore->fresh()->status);
        $this->assertNotNull($attachment->fresh());
        $job = Queue::pushed(TearDownManagedDatabase::class)->sole();
        $this->assertSame('builds', $job->queue);
        $this->assertArrayNotHasKey('password', $job->attachment);

        $job->handle(app(Runner::class));

        $destroy = $this->tofu->commands()[4];
        $this->assertSame(['destroy', '-auto-approve', '-input=false', '-no-color', '-suppress-forget-errors', '-var-file=opencloud.tfvars.json'], $destroy);
        $this->assertSame("env_{$environment->id}", $this->tofu->ran[4]['variables']['database']);
        $this->assertNull($attachment->fresh());
        $this->assertNull($datastore->fresh());
        $this->assertSame(
            ['datastore.attached', 'datastore.destroy_requested', 'datastore.destroyed'],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_a_failed_destroy_keeps_the_records_with_the_error(): void
    {
        $environment = $this->environment();
        $attachment = app(AttachDatastore::class)->handle($environment, null, DatastoreKind::Mysql, DatastoreSource::Managed);
        app(DetachDatastore::class)->destroy($attachment, null);
        $this->tofu->failOn = 'destroy';
        $this->tofu->error = 'Error: Error deleting database cluster: 500';

        Queue::pushed(TearDownManagedDatabase::class)->sole()->handle(app(Runner::class));

        $datastore = $attachment->datastore->fresh();
        $this->assertSame([DatastoreStatus::Failed, 'The destroy failed. tofu destroy failed. Error: Error deleting database cluster: 500'], [$datastore->status, $datastore->error]);
        $this->assertNotNull($attachment->fresh());
    }

    public function test_the_nightly_dump_skips_managed_databases(): void
    {
        $standard = Datastore::factory()->for($this->cluster)->create(['kind' => DatastoreKind::Mysql]);
        Datastore::factory()->for($this->cluster)->create(['kind' => DatastoreKind::Mysql, 'source' => DatastoreSource::Managed]);

        collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'nightly-backups')->run($this->app);

        $this->assertSame([$standard->id], Queue::pushed(BackupDatastore::class)->map(fn (BackupDatastore $job) => $job->datastore->id)->all());
    }

    private function environment(): Environment
    {
        return Environment::factory()->for(Application::factory()->for($this->cluster))->create();
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function envOf(Environment $environment): array
    {
        return $environment->envVars()->where('source', EnvVarSource::Datastore)->orderBy('key')->get()
            ->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret]])
            ->all();
    }

    private function assertRefused(Environment $environment, DatastoreKind $kind, string $message): void
    {
        try {
            app(AttachDatastore::class)->handle($environment, null, $kind, DatastoreSource::Managed);
            $this->fail('The attach was not refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame(0, DatastoreAttachment::query()->count());
    }
}
