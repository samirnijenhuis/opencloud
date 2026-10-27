<?php

namespace Tests\Domains\Clusters\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Actions\CreateCluster;
use App\Domains\Clusters\Actions\DestroyCluster;
use App\Domains\Clusters\Actions\ReinstallClusterParts;
use App\Domains\Clusters\ClusterProviders;
use App\Domains\Clusters\DigitalOceanCluster;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Jobs\InstallClusterParts;
use App\Domains\Clusters\Jobs\ProvisionCluster;
use App\Domains\Clusters\Jobs\TearDownCluster;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Members\Models\User;
use App\Support\Tofu\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

class CreatedClusterTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_FAKE_TOKEN_abcdef0123456789';

    private const KUBECONFIG = "apiVersion: v1\nusers:\n- name: opencloud\n  user:\n    token: SA_TOKEN_SECRET\n";

    private const OUTPUTS = [
        'cluster_id' => 'c0ffee00-1111-2222-3333-444455556666',
        'vpc_id' => 'vpc-0000-1111',
        'kubeconfig' => self::KUBECONFIG,
        'registry_endpoint' => 'registry.digitalocean.com/acme',
        'registry_username' => 'REGISTRY_USER_SECRET',
        'registry_password' => 'REGISTRY_PASSWORD_SECRET',
    ];

    private string $data;

    private FakeTofu $tofu;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->data = sys_get_temp_dir().'/opencloud-created-cluster-'.getmypid();
        config(['opencloud.data_path' => $this->data]);
        $this->tofu = FakeTofu::install();
        $this->tofu->outputs = self::OUTPUTS;
        ProviderCredential::factory()->create(['provider' => 'digitalocean', 'token' => self::TOKEN]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->data);

        parent::tearDown();
    }

    public function test_create_stores_a_created_cluster_and_queues_the_provision(): void
    {
        $owner = User::factory()->owner()->create();

        $cluster = app(CreateCluster::class)->execute($owner, new DigitalOceanCluster, $this->form());

        $this->assertSame(
            ['ams3-prod', ClusterKind::Created, 'digitalocean', ClusterStatus::Provisioning, 'ams3', 's-2vcpu-4gb', 1, 3],
            [$cluster->name, $cluster->kind, $cluster->provider, $cluster->status, $cluster->region, $cluster->node_size, $cluster->min_nodes, $cluster->max_nodes],
        );
        $this->assertSame([$cluster->id], Queue::pushed(ProvisionCluster::class)->map(fn (ProvisionCluster $job) => $job->cluster->id)->all());
        $this->assertSame('builds', Queue::pushed(ProvisionCluster::class)->first()->queue);
        $event = AuditEvent::query()->sole();
        $this->assertSame(['cluster.created', $owner->id, $this->form()], [$event->action, $event->user_id, $event->context]);
    }

    public function test_create_without_a_digitalocean_key_is_refused(): void
    {
        ProviderCredential::query()->delete();

        try {
            app(CreateCluster::class)->execute(User::factory()->owner()->create(), new DigitalOceanCluster, $this->form());
            $this->fail('The create was not refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['name' => ['Add a DigitalOcean key in Settings, Provider keys, first.']], $e->errors());
        }

        $this->assertSame(0, Cluster::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_provision_creates_the_registry_when_the_account_has_none_then_stores_the_result_and_installs_the_parts(): void
    {
        Http::fake(['api.digitalocean.com/v2/registry' => Http::response(['id' => 'not_found', 'message' => 'The resource you requested could not be found.'], 404)]);
        $cluster = $this->createdCluster();

        $this->provision($cluster);

        $variables = $this->tofu->ran[1]['variables'];
        $this->assertMatchesRegularExpression('/^opencloud-[a-z0-9]{10}$/', $variables['registry_name']);
        $this->assertSame(
            ['name' => 'ams3-prod', 'region' => 'ams3', 'node_size' => 's-2vcpu-4gb', 'min_nodes' => 1, 'max_nodes' => 3, 'create_registry' => true],
            array_diff_key($variables, ['registry_name' => true]),
        );
        $this->assertSame([['init'], ['apply'], ['output']], array_map(fn (array $args) => [$args[0]], $this->tofu->commands()));
        $this->assertSame("{$this->data}/tofu/cluster-{$cluster->id}", $this->tofu->ran[0]['path']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        $cluster->refresh();
        $this->assertSame(ClusterStatus::Installing, $cluster->status);
        $this->assertNull($cluster->error);
        $this->assertSame(self::KUBECONFIG, $cluster->kubeconfig);
        $this->assertSame(['c0ffee00-1111-2222-3333-444455556666', 'vpc-0000-1111'], [$cluster->provider_cluster_id, $cluster->provider_vpc_id]);
        $this->assertSame(['registry.digitalocean.com/acme', 'REGISTRY_USER_SECRET', 'REGISTRY_PASSWORD_SECRET', false], [
            $cluster->registry()->address, $cluster->registry()->username, $cluster->registry()->password, $cluster->registry()->insecure,
        ]);
        $this->assertSame([$cluster->id], Queue::pushed(InstallClusterParts::class)->map(fn (InstallClusterParts $job) => $job->cluster->id)->all());
    }

    public function test_provision_reuses_the_registry_of_the_account(): void
    {
        Http::fake(['api.digitalocean.com/v2/registry' => Http::response(['registry' => ['name' => 'acme', 'region' => 'fra1']])]);

        $this->provision($this->createdCluster());

        $this->assertSame(['acme', false], [$this->tofu->ran[1]['variables']['registry_name'], $this->tofu->ran[1]['variables']['create_registry']]);
    }

    public function test_a_failed_apply_fails_the_cluster_with_the_tofu_error_and_installs_nothing(): void
    {
        Http::fake(['api.digitalocean.com/v2/registry' => Http::response(['registry' => ['name' => 'acme']])]);
        $this->tofu->failOn = 'apply';
        $this->tofu->error = 'Error: Error creating Kubernetes cluster: 422 (request "x") token '.self::TOKEN.' is limited';
        $cluster = $this->createdCluster();

        $this->provision($cluster);

        $cluster->refresh();
        $this->assertSame(ClusterStatus::Failed, $cluster->status);
        $this->assertSame('tofu apply failed. Error: Error creating Kubernetes cluster: 422 (request "x") token ******** is limited', $cluster->error);
        $this->assertNull($cluster->kubeconfig);
        Queue::assertNotPushed(InstallClusterParts::class);
    }

    public function test_provision_without_a_key_fails_with_a_clear_error_and_runs_no_tofu(): void
    {
        $cluster = $this->createdCluster();
        ProviderCredential::query()->delete();

        $this->provision($cluster);

        $this->assertSame([ClusterStatus::Failed, 'There is no DigitalOcean key. Add one in Settings, Provider keys.'], [$cluster->fresh()->status, $cluster->fresh()->error]);
        $this->assertSame([], $this->tofu->ran);
    }

    public function test_reinstall_of_a_created_cluster_runs_the_provision_again(): void
    {
        $cluster = $this->createdCluster(['status' => ClusterStatus::Failed, 'error' => 'tofu apply failed.']);

        app(ReinstallClusterParts::class)->execute($cluster, User::factory()->owner()->create());

        Queue::assertPushed(ProvisionCluster::class);
        Queue::assertNotPushed(InstallClusterParts::class);
    }

    public function test_destroy_refuses_a_connected_cluster(): void
    {
        $this->assertDestroyRefused(Cluster::factory()->create(['status' => ClusterStatus::Ready]), 'Opencloud did not create this cluster, so it does not destroy it. Disconnect it instead.');
    }

    public function test_destroy_refuses_while_applications_use_the_cluster(): void
    {
        $cluster = $this->createdCluster(['status' => ClusterStatus::Ready]);
        Application::factory()->for($cluster)->create(['name' => 'shop']);
        Application::factory()->for($cluster)->create(['name' => 'blog']);

        $this->assertDestroyRefused($cluster, 'These applications use this cluster: blog, shop. Remove them first.');
    }

    public function test_destroy_refuses_while_the_cluster_has_managed_databases(): void
    {
        $cluster = $this->createdCluster(['status' => ClusterStatus::Ready]);
        Datastore::factory()->for($cluster)->create(['source' => DatastoreSource::Managed]);

        $this->assertDestroyRefused($cluster, 'This cluster has managed resources. Detach them first.');
    }

    public function test_destroy_refuses_while_opentofu_runs(): void
    {
        $this->assertDestroyRefused($this->createdCluster(), 'OpenTofu still runs for this cluster. Wait until it ends.');
    }

    public function test_destroy_runs_tofu_destroy_then_removes_the_cluster_and_its_standard_datastores(): void
    {
        Http::fake(['api.digitalocean.com/v2/registry' => Http::response(['registry' => ['name' => 'acme']])]);
        $owner = User::factory()->owner()->create();
        $cluster = $this->createdCluster(['status' => ClusterStatus::Ready]);
        Datastore::factory()->for($cluster)->create(['source' => DatastoreSource::Standard]);

        app(DestroyCluster::class)->execute($cluster, $owner);

        $this->assertSame(ClusterStatus::Destroying, $cluster->fresh()->status);
        $job = Queue::pushed(TearDownCluster::class)->sole();
        $this->assertSame('builds', $job->queue);

        $job->handle(app(Runner::class), app(ClusterProviders::class));

        $this->assertSame([['init'], ['destroy']], array_map(fn (array $args) => [$args[0]], $this->tofu->commands()));
        $this->assertContains('-suppress-forget-errors', $this->tofu->commands()[1]);
        $this->assertSame(['acme', false], [$this->tofu->ran[1]['variables']['registry_name'], $this->tofu->ran[1]['variables']['create_registry']]);
        $this->assertNull(Cluster::query()->find($cluster->id));
        $this->assertSame(0, Datastore::query()->count());
        $this->assertSame(
            [['cluster.destroy_requested', $owner->id], ['cluster.destroyed', null]],
            AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->user_id])->all(),
        );
    }

    public function test_a_failed_destroy_keeps_the_cluster_with_the_error(): void
    {
        Http::fake(['api.digitalocean.com/v2/registry' => Http::response(['registry' => ['name' => 'acme']])]);
        $this->tofu->failOn = 'destroy';
        $this->tofu->error = 'Error: Error deleting Kubernetes cluster: 500 Server Error';
        $cluster = $this->createdCluster(['status' => ClusterStatus::Destroying]);

        (new TearDownCluster($cluster))->handle(app(Runner::class), app(ClusterProviders::class));

        $cluster->refresh();
        $this->assertSame(ClusterStatus::Failed, $cluster->status);
        $this->assertSame('The destroy failed. tofu destroy failed. Error: Error deleting Kubernetes cluster: 500 Server Error', $cluster->error);
    }

    /** @return array{name: string, region: string, node_size: string, min_nodes: int, max_nodes: int} */
    private function form(): array
    {
        return ['name' => 'ams3-prod', 'region' => 'ams3', 'node_size' => 's-2vcpu-4gb', 'min_nodes' => 1, 'max_nodes' => 3];
    }

    /** @param  array<string, mixed>  $attributes */
    private function createdCluster(array $attributes = []): Cluster
    {
        return Cluster::factory()->create([
            ...$this->form(),
            'kind' => ClusterKind::Created,
            'provider' => 'digitalocean',
            'kubeconfig' => null,
            'status' => ClusterStatus::Provisioning,
            ...$attributes,
        ]);
    }

    private function provision(Cluster $cluster): void
    {
        (new ProvisionCluster($cluster))->handle(app(Runner::class), app(ClusterProviders::class));
    }

    private function assertDestroyRefused(Cluster $cluster, string $message): void
    {
        $status = $cluster->status;

        try {
            app(DestroyCluster::class)->execute($cluster, User::factory()->owner()->create());
            $this->fail('The destroy was not refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['cluster' => [$message]], $e->errors());
        }

        $this->assertSame($status, $cluster->fresh()->status);
        Queue::assertNotPushed(TearDownCluster::class);
    }
}
