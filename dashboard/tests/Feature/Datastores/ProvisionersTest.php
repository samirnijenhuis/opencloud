<?php

namespace Tests\Feature\Datastores;

use App\Actions\AttachDatastore;
use App\Clusters\ClusterStatus;
use App\Datastores\DatastoreStatus;
use App\Datastores\Provisioners;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\EnvVarSource;
use App\Kube\FakeKubectl;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

/**
 * The registry keys on kind and source together, so the code of a managed datastore never reaches kubectl. The
 * lifecycle test proves it at run time, and the source scans keep the old ways to reach an engine out of the code.
 */
class ProvisionersTest extends TestCase
{
    use RefreshDatabase;

    private const OUTPUTS = [
        'host' => 'private-ams3-prod-pg-1-do-user-1.db.ondigitalocean.com',
        'port' => 25060,
        'database' => 'env_1',
        'user' => 'env_1',
        'password' => 'DO_GENERATED_PASSWORD_SECRET',
    ];

    private string $data;

    private FakeKubectl $kubectl;

    private FakeTofu $tofu;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = sys_get_temp_dir().'/opencloud-provisioners-'.getmypid();
        config(['opencloud.data_path' => $this->data]);
        $this->kubectl = FakeKubectl::install();
        $this->tofu = FakeTofu::install();
        $this->tofu->outputs = self::OUTPUTS;
        ProviderCredential::factory()->create(['provider' => 'digitalocean', 'token' => 'dop_v1_FAKE_TOKEN_provisioners']);
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

    public function test_every_method_of_a_managed_provisioner_runs_opentofu_and_never_kubectl(): void
    {
        $application = Application::factory()->for($this->cluster)->create();
        $seen = [];

        foreach (Provisioners::table() as ['kind' => $kind, 'source' => $source, 'provisioner' => $provisioner]) {
            Queue::fake();
            $kubectlBefore = count($this->kubectl->calls);
            $tofuBefore = count($this->tofu->ran);
            $environment = Environment::factory()->for($application)->create();
            $name = $kind->family()->isNamed() ? 'main' : null;

            $refusal = $provisioner->refusalOn($this->cluster);
            $attachment = $provisioner->attach($environment, $name, $name !== null);
            $provisioner->retry($attachment);
            $provisioner->provision($attachment->fresh());
            $provisioned = $attachment->fresh();
            $connection = $provisioner->connection($provisioned);
            $capabilities = $provisioner->capabilities();
            $provisioner->detach($provisioned, null, destroyData: true);
            $provisioner->release($provisioned->datastore, Arr::except($provisioned->getAttributes(), ['password']), destroyData: true);

            $seen["{$source->value} {$kind->value}"] = [
                'refusal' => $refusal,
                'status' => $provisioned->status->value,
                'connection' => class_basename($connection),
                'dumps' => $capabilities->dumps !== null,
                'queued' => array_map('count', Queue::pushedJobs()),
                'kubectl calls' => count($this->kubectl->calls) > $kubectlBefore,
                'tofu' => array_map(fn (array $run) => $run['args'][0], array_slice($this->tofu->ran, $tofuBefore)),
                'datastore kept' => Datastore::query()->whereKey($provisioned->datastore_id)->exists(),
            ];
        }

        $local = fn (string $connection, bool $dumps) => [
            'refusal' => null,
            'status' => 'ready',
            'connection' => $connection,
            'dumps' => $dumps,
            'queued' => ['App\Jobs\ProvisionDatastoreAttachment' => 2, 'App\Jobs\RevokeDatastoreAccess' => 1],
            'kubectl calls' => true,
            'tofu' => [],
            'datastore kept' => true,
        ];
        $managed = fn (string $connection, array $tofu) => [
            'refusal' => null,
            'status' => 'ready',
            'connection' => $connection,
            'dumps' => false,
            'queued' => ['App\Jobs\ProvisionManagedDatabase' => 2, 'App\Jobs\TearDownManagedDatabase' => 1],
            'kubectl calls' => false,
            'tofu' => $tofu,
            'datastore kept' => false,
        ];
        $oneStack = ['init', 'apply', 'output', 'init', 'destroy'];
        $this->assertSame([
            'standard mysql' => $local('SqlConnection', dumps: true),
            'standard postgres' => $local('SqlConnection', dumps: true),
            'standard valkey' => $local('KeyValueConnection', dumps: false),
            'standard rustfs' => $local('BucketConnection', dumps: false),
            'managed mysql' => $managed('SqlConnection', $oneStack),
            'managed postgres' => $managed('SqlConnection', $oneStack),
            'managed valkey' => $managed('KeyValueConnection', $oneStack),
        ], $seen);
    }

    public function test_a_managed_postgres_whose_apply_failed_is_ready_with_its_env_after_a_retry(): void
    {
        $environment = Environment::factory()->for(Application::factory()->for($this->cluster))->create();
        $owner = User::factory()->owner()->create();
        $this->tofu->failOn = 'apply';
        $this->tofu->failTimes = 1;
        $this->tofu->error = 'Error: Error creating database cluster: 422 You have reached the database cluster limit';

        $attachment = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Postgres, DatastoreSource::Managed);

        $failed = 'tofu apply failed. Error: Error creating database cluster: 422 You have reached the database cluster limit';
        $this->assertSame([DatastoreStatus::Failed, $failed], [$attachment->fresh()->status, $attachment->fresh()->error]);
        $this->assertSame([], $this->envOf($environment));

        $again = app(AttachDatastore::class)->handle($environment, $owner, DatastoreKind::Postgres, DatastoreSource::Managed, 'main');

        $id = $environment->id;
        $this->assertTrue($again->is($attachment));
        $this->assertSame([DatastoreStatus::Ready, null, DatastoreStatus::Ready], [$attachment->fresh()->status, $attachment->fresh()->error, $attachment->datastore->fresh()->status]);
        $this->assertSame([
            'DB_CONNECTION' => ['pgsql', false],
            'DB_DATABASE' => ["env_{$id}", false],
            'DB_HOST' => ['private-ams3-prod-pg-1-do-user-1.db.ondigitalocean.com', false],
            'DB_PASSWORD' => ['DO_GENERATED_PASSWORD_SECRET', true],
            'DB_PORT' => ['25060', false],
            'DB_USERNAME' => ["env_{$id}", false],
        ], $this->envOf($environment));
        $this->assertSame(['init', 'apply', 'init', 'apply', 'output'], array_map(fn (array $run) => $run['args'][0], $this->tofu->ran));
        $this->assertSame([], $this->kubectl->calls);
    }

    public function test_only_the_registry_creates_an_engine(): void
    {
        $this->assertSame(
            ['app/Datastores/Provisioners.php'],
            $this->filesWhere(['app', 'routes'], fn (array $code, int $i) => strtolower($code[$i]) === 'new' && str_ends_with($this->shortName($code[$i + 1]), 'Engine')),
        );
    }

    public function test_no_code_picks_an_engine_by_the_kind_alone(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere(['app', 'routes'], fn (array $code, int $i) => ($this->shortName($code[$i]) === 'Engines' && $code[$i + 1] === '::')
                || ($code[$i] === 'isSql' && $code[$i + 1] === '(')
                || (strtolower($code[$i]) === 'instanceof' && $this->shortName($code[$i + 1]) === 'SqlEngine')),
        );
    }

    public function test_no_action_or_job_branches_on_the_managed_source(): void
    {
        // DestroyCluster refuses a cluster that still has managed databases. That is UI policy, not provisioning.
        $this->assertSame(
            ['app/Actions/DestroyCluster.php'],
            $this->filesWhere(['app/Actions', 'app/Jobs'], fn (array $code, int $i) => $this->shortName($code[$i]) === 'DatastoreSource'
                && $code[$i + 1] === '::'
                && $code[$i + 2] === 'Managed'),
        );
    }

    public function test_only_the_local_provisioner_names_kubectl(): void
    {
        $files = collect(Provisioners::table())
            ->map(fn (array $row) => str_replace(base_path().'/', '', (new ReflectionClass($row['provisioner']))->getFileName()))
            ->merge(collect(Finder::create()->files()->name('Managed*.php')->in(app_path('Datastores')))->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname())))
            ->unique()
            ->sort()
            ->values();

        $this->assertSame(
            [
                'app/Datastores/LocalProvisioner.php' => true,
                'app/Datastores/ManagedDatabase.php' => false,
                'app/Datastores/ManagedResource.php' => false,
                'app/Datastores/ManagedResources.php' => false,
                'app/Datastores/ManagedStack.php' => false,
                'app/Datastores/ManagedValkey.php' => false,
            ],
            $files->mapWithKeys(fn (string $file) => [$file => collect($this->code($file))->contains(fn (string $token) => str_contains($token, 'Kubectl'))])->all(),
        );
    }

    /**
     * The PHP files under the folders with at least one token where $matches holds, relative to the app root.
     *
     * @param  list<string>  $folders
     * @param  callable(list<string>, int): bool  $matches
     * @return list<string>
     */
    private function filesWhere(array $folders, callable $matches): array
    {
        $files = [];

        foreach (Finder::create()->files()->name('*.php')->in(array_map(base_path(...), $folders)) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            $code = [...$this->code($relative), '', '', ''];

            foreach (array_keys($code) as $i) {
                if ($code[$i] !== '' && $matches($code, $i)) {
                    $files[] = $relative;
                    break;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The tokens of a file without comments and whitespace, so a docblock that names an old call does not count.
     *
     * @return list<string>
     */
    private function code(string $relative): array
    {
        $tokens = token_get_all(file_get_contents(base_path($relative)));
        $code = array_filter($tokens, fn (array|string $token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true));

        return array_values(array_map(fn (array|string $token) => is_array($token) ? $token[1] : $token, $code));
    }

    /** App\Datastores\Engines and \App\Datastores\Engines are both Engines. */
    private function shortName(string $token): string
    {
        return ltrim(strrchr('\\'.$token, '\\'), '\\');
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function envOf(Environment $environment): array
    {
        return $environment->envVars()->where('source', EnvVarSource::Datastore)->orderBy('key')->get()
            ->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret]])
            ->all();
    }
}
