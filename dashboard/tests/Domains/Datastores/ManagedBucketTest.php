<?php

namespace Tests\Domains\Datastores;

use App\Domains\Applications\Models\Application;
use App\Domains\Backups\Jobs\CopyBuckets;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\DetachDatastore;
use App\Domains\Datastores\BucketConnection;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Jobs\ProvisionManagedDatabase;
use App\Domains\Datastores\Jobs\TearDownManagedDatabase;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

class ManagedBucketTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_FAKE_TOKEN_managed_bucket_0123';

    private string $data;

    private FakeTofu $tofu;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        $this->data = sys_get_temp_dir().'/opencloud-managed-bucket-'.getmypid();
        config(['opencloud.data_path' => $this->data]);
        $this->tofu = FakeTofu::install();
        $this->tofu->outputsOf = [
            'spacesadmin' => ['access_key_id' => 'DO00ADMINKEYID', 'secret_key' => 'DO_ADMIN_SECRET_KEY'],
            'bucket' => [
                'endpoint' => 'https://ams3.digitaloceanspaces.com',
                'region' => 'ams3',
                'bucket' => 'ams3-prod-env-1-k3x9q2m7',
                'access_key_id' => 'DO00BUCKETKEYID',
                'secret_key' => 'DO_BUCKET_SECRET_KEY',
            ],
        ];
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
        Str::createRandomStringsNormally();
        File::deleteDirectory($this->data);

        parent::tearDown();
    }

    public function test_the_admin_key_exists_only_while_the_bucket_stack_runs(): void
    {
        $attachment = $this->attach($this->environment());

        $this->assertSame(['ams3-prod-env-1-k3x9q2m7', null, DatastoreSource::Managed, ''], [$attachment->database_name, $attachment->host, $attachment->datastore->source, $attachment->datastore->version]);

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $id = $attachment->datastore_id;
        $this->assertSame(
            ["spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output", "bucket-{$id} init", "bucket-{$id} apply", "bucket-{$id} output", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy"],
            $this->runs(),
        );
        $this->assertSame(['name' => 'ams3-prod-env-1-k3x9q2m7-opentofu'], $this->tofu->ran[1]['variables']);
        $this->assertSame(['DIGITALOCEAN_TOKEN' => self::TOKEN], $this->credentials(1));
        $this->assertSame(['name' => 'ams3-prod-env-1-k3x9q2m7', 'region' => 'ams3'], $this->tofu->ran[4]['variables']);
        $this->assertSame([
            'DIGITALOCEAN_TOKEN' => self::TOKEN,
            'SPACES_ACCESS_KEY_ID' => 'DO00ADMINKEYID',
            'SPACES_SECRET_ACCESS_KEY' => 'DO_ADMIN_SECRET_KEY',
        ], $this->credentials(4));
    }

    public function test_the_attachment_keeps_the_bucket_key_and_never_the_admin_key(): void
    {
        $attachment = $this->attach($this->environment());

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $attachment->refresh();
        $this->assertSame(
            ['https://ams3.digitaloceanspaces.com', 'ams3', 'ams3-prod-env-1-k3x9q2m7', 'DO00BUCKETKEYID', 'DO_BUCKET_SECRET_KEY', false, DatastoreStatus::Ready],
            [$attachment->host, $attachment->region, $attachment->database_name, $attachment->username, $attachment->password, $attachment->path_style, $attachment->status],
        );
        $this->assertEquals(
            new BucketConnection('https://ams3.digitaloceanspaces.com', 'ams3', 'ams3-prod-env-1-k3x9q2m7', 'DO00BUCKETKEYID', 'DO_BUCKET_SECRET_KEY', pathStyle: false),
            BucketConnection::of($attachment),
        );
    }

    public function test_the_apply_writes_the_laravel_s3_values(): void
    {
        $environment = $this->environment();
        $this->attach($environment);

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $this->assertSame([
            'AWS_ACCESS_KEY_ID' => ['DO00BUCKETKEYID', false],
            'AWS_BUCKET' => ['ams3-prod-env-1-k3x9q2m7', false],
            'AWS_DEFAULT_REGION' => ['ams3', false],
            'AWS_ENDPOINT' => ['https://ams3.digitaloceanspaces.com', false],
            'AWS_SECRET_ACCESS_KEY' => ['DO_BUCKET_SECRET_KEY', true],
            'AWS_USE_PATH_STYLE_ENDPOINT' => ['false', false],
            'FILESYSTEM_DISK' => ['s3', false],
        ], $this->envOf($environment));
    }

    public function test_a_failed_apply_keeps_the_bucket_name_and_attach_again_finishes_it(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $this->tofu->failOn = 'apply';
        $this->tofu->failTimes = 1;
        $this->tofu->error = 'Error: Error creating Spaces key: POST https://api.digitalocean.com/v2/spaces/keys: 403 You are not authorized to perform this operation';
        $attachment = $this->attach($environment, $owner);

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $failed = 'tofu apply failed. Error: Error creating Spaces key: POST https://api.digitalocean.com/v2/spaces/keys: 403 You are not authorized to perform this operation';
        $this->assertSame([DatastoreStatus::Failed, $failed], [$attachment->fresh()->status, $attachment->fresh()->error]);
        $this->assertSame([], $this->envOf($environment));

        $again = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Rustfs, DatastoreSource::Managed);
        Queue::pushed(ProvisionManagedDatabase::class)->last()->handle();

        $id = $attachment->datastore_id;
        $this->assertTrue($again->is($attachment));
        $this->assertSame(
            [
                "spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy",
                "spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output", "bucket-{$id} init", "bucket-{$id} apply", "bucket-{$id} output", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy",
            ],
            $this->runs(),
        );
        $this->assertSame('ams3-prod-env-1-k3x9q2m7', $this->tofu->ran[8]['variables']['name']);
        $this->assertSame([DatastoreStatus::Ready, null, DatastoreStatus::Ready], [$attachment->fresh()->status, $attachment->fresh()->error, $attachment->datastore->fresh()->status]);
        $this->assertSame(['DO00BUCKETKEYID', false], $this->envOf($environment)['AWS_ACCESS_KEY_ID']);
    }

    public function test_detach_destroys_the_bucket_with_the_admin_key_and_then_the_admin_key(): void
    {
        $environment = $this->environment();
        $owner = User::factory()->owner()->create();
        $attachment = $this->attach($environment, $owner);
        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();
        $datastore = $attachment->datastore;

        app(DetachDatastore::class)->handle($attachment->fresh(), $owner);

        $this->assertSame([], $this->envOf($environment));
        $job = Queue::pushed(TearDownManagedDatabase::class)->sole();
        $this->assertArrayNotHasKey('password', $job->attachment);

        $job->handle();

        $id = $datastore->id;
        $this->assertSame(
            ["spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output", "bucket-{$id} init", "bucket-{$id} destroy", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy"],
            array_slice($this->runs(), 8),
        );
        $this->assertSame(['name' => 'ams3-prod-env-1-k3x9q2m7', 'region' => 'ams3'], $this->tofu->ran[12]['variables']);
        $this->assertSame('DO_ADMIN_SECRET_KEY', $this->credentials(12)['SPACES_SECRET_ACCESS_KEY']);
        $this->assertNull($attachment->fresh());
        $this->assertNull($datastore->fresh());
    }

    public function test_a_failed_bucket_destroy_still_destroys_the_admin_key_and_keeps_the_records(): void
    {
        $attachment = $this->attach($this->environment());
        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();
        app(DetachDatastore::class)->destroy($attachment->fresh(), null);
        $this->tofu->failOn = 'destroy';
        $this->tofu->failStack = 'bucket';
        $this->tofu->failTimes = 1;
        $this->tofu->error = 'Error: deleting Spaces bucket: 503 Service Unavailable';

        Queue::pushed(TearDownManagedDatabase::class)->sole()->handle();

        $datastore = $attachment->datastore->fresh();
        $id = $datastore->id;
        $this->assertSame([DatastoreStatus::Failed, 'The destroy failed. tofu destroy failed. Error: deleting Spaces bucket: 503 Service Unavailable'], [$datastore->status, $datastore->error]);
        $this->assertSame(
            ["spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output", "bucket-{$id} init", "bucket-{$id} destroy", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy"],
            array_slice($this->runs(), 8),
        );
        $this->assertNotNull($attachment->fresh());

        Queue::pushed(TearDownManagedDatabase::class)->sole()->handle();

        $this->assertSame(
            ["spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output", "bucket-{$id} init", "bucket-{$id} destroy", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy"],
            array_slice($this->runs(), -7),
        );
        $this->assertNull($datastore->fresh());
    }

    public function test_a_failed_bucket_apply_destroys_the_admin_key_and_shows_the_bucket_error(): void
    {
        $attachment = $this->attach($this->environment());
        $this->tofu->failOn = 'apply';
        $this->tofu->failStack = 'bucket';
        $this->tofu->error = 'Error: creating Spaces bucket: 503 Service Unavailable';

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $id = $attachment->datastore_id;
        $this->assertSame(
            ["spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output", "bucket-{$id} init", "bucket-{$id} apply", "spacesadmin-{$id} init", "spacesadmin-{$id} destroy"],
            $this->runs(),
        );
        $this->assertSame([DatastoreStatus::Failed, 'tofu apply failed. Error: creating Spaces bucket: 503 Service Unavailable'], [$attachment->fresh()->status, $attachment->fresh()->error]);
        Sleep::assertNeverSlept();
    }

    public function test_the_bucket_step_runs_again_after_an_unknown_access_key_error(): void
    {
        $attachment = $this->attach($this->environment());
        $this->tofu->failOn = 'apply';
        $this->tofu->failStack = 'bucket';
        $this->tofu->failTimes = 2;
        $this->tofu->error = 'Error: creating Spaces bucket: InvalidAccessKeyId: The Access Key Id you provided does not exist in our records.';

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $id = $attachment->datastore_id;
        $this->assertSame(
            [
                "spacesadmin-{$id} init", "spacesadmin-{$id} apply", "spacesadmin-{$id} output",
                "bucket-{$id} init", "bucket-{$id} apply", "bucket-{$id} init", "bucket-{$id} apply", "bucket-{$id} init", "bucket-{$id} apply", "bucket-{$id} output",
                "spacesadmin-{$id} init", "spacesadmin-{$id} destroy",
            ],
            $this->runs(),
        );
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(10)->seconds()]);
        $this->assertSame(DatastoreStatus::Ready, $attachment->fresh()->status);
    }

    public function test_the_bucket_step_gives_up_after_five_tries_with_an_unknown_access_key(): void
    {
        $attachment = $this->attach($this->environment());
        $this->tofu->failOn = 'apply';
        $this->tofu->failStack = 'bucket';
        $this->tofu->error = 'Error: creating Spaces bucket: InvalidAccessKeyId';

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $id = $attachment->datastore_id;
        $this->assertSame(5, count(array_keys($this->runs(), "bucket-{$id} apply")));
        $this->assertSame("spacesadmin-{$id} destroy", Arr::last($this->runs()));
        Sleep::assertSleptTimes(4);
        $this->assertSame(DatastoreStatus::Failed, $attachment->fresh()->status);
    }

    public function test_an_admin_key_that_stays_after_a_good_apply_fails_the_run_and_the_next_run_destroys_it(): void
    {
        $attachment = $this->attach($this->environment());
        $this->tofu->failOn = 'destroy';
        $this->tofu->failStack = 'spacesadmin';
        $this->tofu->failTimes = 1;
        $this->tofu->error = 'Error: deleting Spaces key: 503 Service Unavailable';

        Queue::pushed(ProvisionManagedDatabase::class)->sole()->handle();

        $this->assertSame(DatastoreStatus::Failed, $attachment->fresh()->status);

        app(AttachDatastore::class)->handle($attachment->environment, null, DatastoreKind::Rustfs, DatastoreSource::Managed);
        Queue::pushed(ProvisionManagedDatabase::class)->last()->handle();

        $id = $attachment->datastore_id;
        $this->assertSame("spacesadmin-{$id} destroy", Arr::last($this->runs()));
        $this->assertSame(DatastoreStatus::Ready, $attachment->fresh()->status);
    }

    public function test_the_nightly_bucket_copy_skips_a_spaces_bucket(): void
    {
        $standard = Datastore::factory()->for($this->cluster)->create(['kind' => DatastoreKind::Rustfs]);
        Datastore::factory()->for($this->cluster)->create(['kind' => DatastoreKind::Rustfs, 'source' => DatastoreSource::Managed]);

        collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'nightly-backups')->run($this->app);

        $this->assertSame([$standard->id], Queue::pushed(CopyBuckets::class)->map(fn (CopyBuckets $job) => $job->datastore->id)->all());
    }

    private function attach(Environment $environment, ?User $by = null): DatastoreAttachment
    {
        Str::createRandomStringsUsing(fn () => 'K3x9Q2m7');

        return app(AttachDatastore::class)->handle($environment, $by, DatastoreKind::Rustfs, DatastoreSource::Managed);
    }

    private function environment(): Environment
    {
        return Environment::factory()->for(Application::factory()->for($this->cluster))->create();
    }

    /** @return list<string> "<work folder> <subcommand>" for each tofu call */
    private function runs(): array
    {
        return array_map(fn (array $run) => basename($run['path']).' '.$run['args'][0], $this->tofu->ran);
    }

    /** @return array<string, string> the provider credentials that the tofu call got */
    private function credentials(int $run): array
    {
        return Arr::only($this->tofu->ran[$run]['env'], ['DIGITALOCEAN_TOKEN', 'SPACES_ACCESS_KEY_ID', 'SPACES_SECRET_ACCESS_KEY']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function envOf(Environment $environment): array
    {
        return $environment->envVars()->where('source', EnvVarSource::Datastore)->orderBy('key')->get()
            ->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret]])
            ->all();
    }
}
