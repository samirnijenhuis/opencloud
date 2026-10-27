<?php

namespace Tests\Domains\Datastores;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Actions\BackupNow;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\DetachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Jobs\ProvisionManagedDatabase;
use App\Domains\Datastores\Jobs\TearDownManagedDatabase;
use App\Domains\Datastores\KeyValueConnection;
use App\Domains\Datastores\Provisioners;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

class ManagedValkeyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_FAKE_TOKEN_managed_valkey_0123';

    private const OUTPUTS = [
        'host' => 'private-ams3-prod-valkey-1-do-user-1.k.db.ondigitalocean.com',
        'port' => 25061,
        'password' => 'DO_VALKEY_PASSWORD_SECRET',
    ];

    private string $data;

    private FakeTofu $tofu;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->data = sys_get_temp_dir().'/opencloud-managed-valkey-'.getmypid();
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

    public function test_a_local_valkey_attaches_while_the_managed_one_is_being_destroyed(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $attach = app(AttachDatastore::class);
        $managed = $attach->execute($environment, $owner, DatastoreKind::Valkey, DatastoreSource::Managed);
        app(DetachDatastore::class)->execute($managed, $owner);

        $local = $attach->execute($environment, $owner, DatastoreKind::Valkey);

        $this->assertNotSame($managed->id, $local->id);
        $this->assertSame(
            [[DatastoreSource::Managed, DatastoreStatus::Destroying], [DatastoreSource::Standard, DatastoreStatus::Pending]],
            [[$managed->datastore->fresh()->source, $managed->datastore->fresh()->status], [$local->datastore->source, $local->datastore->status]],
        );
    }

    public function test_the_apply_gets_the_valkey_variables_and_stores_a_tls_address(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();

        $attachment = app(AttachDatastore::class)->execute($environment, $owner, DatastoreKind::Valkey, DatastoreSource::Managed);

        $datastore = $attachment->datastore;
        $this->assertSame([DatastoreSource::Managed, '8', null, null], [$datastore->source, $datastore->version, $attachment->name, $attachment->host]);
        $this->assertSame([], $this->envOf($environment));
        $this->assertSame(['environment_id' => $environment->id, 'kind' => 'valkey', 'source' => 'managed'], AuditEvent::query()->sole()->context);

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $this->assertSame("valkey-{$datastore->id}", basename($this->tofu->ran[1]['path']));
        $this->assertSame([
            'name' => "ams3-prod-valkey-{$datastore->id}",
            'engine_version' => '8',
            'size' => 'db-s-1vcpu-1gb',
            'region' => 'ams3',
            'vpc_id' => 'vpc-2222',
            'kubernetes_cluster_id' => 'c0ffee00-1111',
        ], $this->tofu->ran[1]['variables']);
        $this->assertSame(self::TOKEN, $this->tofu->ran[1]['env']['DIGITALOCEAN_TOKEN']);

        $attachment->refresh();
        $this->assertSame(
            ['private-ams3-prod-valkey-1-do-user-1.k.db.ondigitalocean.com', 25061, 'DO_VALKEY_PASSWORD_SECRET', true, DatastoreStatus::Ready],
            [$attachment->host, $attachment->port, $attachment->password, $attachment->tls, $attachment->status],
        );
        $this->assertSame(
            'rediss://:DO_VALKEY_PASSWORD_SECRET@private-ams3-prod-valkey-1-do-user-1.k.db.ondigitalocean.com:25061',
            Provisioners::of($datastore)->connection($attachment)->url(),
        );
        $this->assertEquals(new KeyValueConnection('private-ams3-prod-valkey-1-do-user-1.k.db.ondigitalocean.com', 25061, 'DO_VALKEY_PASSWORD_SECRET', tls: true), KeyValueConnection::of($attachment));
    }

    public function test_the_apply_writes_the_laravel_redis_values(): void
    {
        $environment = $this->environment();
        $attachment = app(AttachDatastore::class)->execute($environment, null, DatastoreKind::Valkey, DatastoreSource::Managed);

        (new ProvisionManagedDatabase($attachment))->handle();

        $this->assertSame([
            'CACHE_STORE' => ['redis', false],
            'REDIS_CLIENT' => ['phpredis', false],
            'REDIS_HOST' => ['tls://private-ams3-prod-valkey-1-do-user-1.k.db.ondigitalocean.com', false],
            'REDIS_PASSWORD' => ['DO_VALKEY_PASSWORD_SECRET', true],
            'REDIS_PORT' => ['25061', false],
        ], $this->envOf($environment));
    }

    public function test_a_failed_apply_is_ready_with_its_env_after_attach_again(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $this->tofu->failOn = 'apply';
        $this->tofu->failTimes = 1;
        $this->tofu->error = 'Error: Error creating database cluster: 422 invalid size slug for engine valkey';
        $attachment = app(AttachDatastore::class)->execute($environment, $owner, DatastoreKind::Valkey, DatastoreSource::Managed);

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $failed = 'tofu apply failed. Error: Error creating database cluster: 422 invalid size slug for engine valkey';
        $this->assertSame([DatastoreStatus::Failed, $failed], [$attachment->fresh()->status, $attachment->fresh()->error]);
        $this->assertSame([DatastoreStatus::Failed, $failed], [$attachment->datastore->fresh()->status, $attachment->datastore->fresh()->error]);
        $this->assertSame([], $this->envOf($environment));

        $again = app(AttachDatastore::class)->execute($environment, $owner, DatastoreKind::Valkey, DatastoreSource::Managed);
        Queue::pushed(ProvisionManagedDatabase::class)->last()->handle();

        $this->assertTrue($again->is($attachment));
        $this->assertSame([DatastoreStatus::Ready, null, DatastoreStatus::Ready], [$attachment->fresh()->status, $attachment->fresh()->error, $attachment->datastore->fresh()->status]);
        $this->assertSame('DO_VALKEY_PASSWORD_SECRET', $this->envOf($environment)['REDIS_PASSWORD'][0]);
        $this->assertSame(['init', 'apply', 'init', 'apply', 'output'], array_map(fn (array $command) => $command[0], $this->tofu->commands()));
    }

    public function test_detach_removes_the_env_values_now_and_destroys_the_valkey_with_opentofu(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $attachment = app(AttachDatastore::class)->execute($environment, $owner, DatastoreKind::Valkey, DatastoreSource::Managed);
        (new ProvisionManagedDatabase($attachment))->handle();
        $datastore = $attachment->datastore;

        app(DetachDatastore::class)->execute($attachment->fresh(), $owner);

        $this->assertSame([], $this->envOf($environment));
        $this->assertSame(DatastoreStatus::Destroying, $datastore->fresh()->status);

        Queue::pushed(TearDownManagedDatabase::class)->sole()->handle();

        $this->assertSame(['destroy', '-auto-approve', '-input=false', '-no-color', '-suppress-forget-errors', '-var-file=opencloud.tfvars.json'], $this->tofu->commands()[4]);
        $this->assertSame(["valkey-{$datastore->id}", "ams3-prod-valkey-{$datastore->id}"], [basename($this->tofu->ran[4]['path']), $this->tofu->ran[4]['variables']['name']]);
        $this->assertNull($attachment->fresh());
        $this->assertNull($datastore->fresh());
        $this->assertSame(
            ['datastore.attached', 'datastore.destroy_requested', 'datastore.destroyed'],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_a_manual_backup_says_why_opencloud_does_not_back_it_up(): void
    {
        $attachment = app(AttachDatastore::class)->execute($this->environment(), null, DatastoreKind::Valkey, DatastoreSource::Managed);

        try {
            app(BackupNow::class)->execute($attachment, User::factory()->owner()->create());
            $this->fail('The backup was not refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('DigitalOcean does not back up Valkey, and opencloud does not either. Keep only data that the app can make again.', $e->getMessage());
        }
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
}
