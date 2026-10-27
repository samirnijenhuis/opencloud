<?php

namespace Tests\Domains\Clusters;

use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\ClusterProvider;
use App\Domains\Clusters\ClusterProviders;
use App\Domains\Clusters\DigitalOceanCluster;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Jobs\InstallClusterParts;
use App\Domains\Clusters\Jobs\ProvisionCluster;
use App\Domains\Clusters\Jobs\TearDownCluster;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Members\Models\User;
use App\Support\Tofu\Runner;
use App\Support\Tofu\Stack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeTofu;
use Tests\TestCase;

class ClusterProviderTest extends TestCase
{
    use RefreshDatabase;

    private string $data;

    private FakeTofu $tofu;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->data = sys_get_temp_dir().'/opencloud-cluster-provider-'.getmypid();
        config(['opencloud.data_path' => $this->data]);
        File::ensureDirectoryExists($this->data.'/modules/fakecloud/cluster');
        File::put($this->data.'/modules/fakecloud/cluster/main.tf', '');
        config(['opencloud.tofu_modules' => $this->data.'/modules']);
        $this->tofu = FakeTofu::install();
        $this->app->instance(ClusterProviders::class, new ClusterProviders([new DigitalOceanCluster, new FakeCloudCluster]));
        ProviderCredential::factory()->create(['provider' => 'fakecloud', 'token' => 'FAKE_CLOUD_TOKEN']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->data);

        parent::tearDown();
    }

    public function test_the_list_page_offers_one_create_button_for_each_provider(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/clusters')->assertInertia(fn (Assert $page) => $page
            ->where('providers', [['key' => 'digitalocean', 'label' => 'DigitalOcean'], ['key' => 'fakecloud', 'label' => 'FakeCloud']]));
    }

    public function test_the_create_page_sends_the_choices_of_the_provider(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/clusters/create/fakecloud')->assertInertia(fn (Assert $page) => $page
            ->component('clusters/create-provider')
            ->where('provider', ['key' => 'fakecloud', 'label' => 'FakeCloud'])
            ->where('regions', [['value' => 'mars1', 'label' => 'Mars (mars1)']])
            ->where('node_sizes', [['value' => 'tiny', 'label' => 'Tiny (tiny)']])
            ->where('max_nodes', 2)
            ->where('pricing_url', 'https://fakecloud.example/pricing')
            ->where('has_key', true));
    }

    public function test_an_unknown_provider_has_no_create_page(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/clusters/create/nowhere')->assertNotFound();
        $this->actingAs($owner)->post('/clusters/create/nowhere', $this->form())->assertNotFound();
    }

    public function test_the_form_validates_against_the_choices_of_the_provider(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/clusters/create/fakecloud', [...$this->form(), 'region' => 'ams3', 'node_size' => 's-2vcpu-4gb', 'max_nodes' => 3])
            ->assertSessionHasErrors(['region', 'node_size', 'max_nodes']);

        $this->assertSame(0, Cluster::query()->count());

        $this->actingAs($owner)->post('/clusters/create/digitalocean', $this->form())->assertSessionHasErrors(['region', 'node_size']);
    }

    public function test_a_second_provider_creates_and_destroys_a_cluster_with_its_own_stack(): void
    {
        $this->tofu->outputs = ['cluster_id' => 'fc-1', 'vpc_id' => 'fc-vpc', 'kubeconfig' => 'KUBECONFIG_FC', 'endpoint' => 'registry.fakecloud.example/acme'];
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/clusters/create/fakecloud', $this->form())->assertRedirect();

        $cluster = Cluster::query()->where('name', 'mars-prod')->sole();
        $this->assertSame(['fakecloud', ClusterKind::CREATED, ClusterStatus::PROVISIONING], [$cluster->provider, $cluster->kind, $cluster->status]);

        Queue::pushed(ProvisionCluster::class)->sole()->handle(app(Runner::class), app(ClusterProviders::class));

        $this->assertSame([['init'], ['apply'], ['output']], array_map(fn (array $args) => [$args[0]], $this->tofu->commands()));
        $this->assertSame('FAKE_CLOUD_TOKEN', $this->tofu->ran[0]['env']['FAKECLOUD_TOKEN']);
        $this->assertSame(['mars1', 'tiny'], [$this->tofu->ran[1]['variables']['region'], $this->tofu->ran[1]['variables']['node_size']]);
        $cluster->refresh();
        $this->assertSame(
            [ClusterStatus::INSTALLING, 'fc-1', 'fc-vpc', 'registry.fakecloud.example/acme'],
            [$cluster->status, $cluster->provider_cluster_id, $cluster->provider_vpc_id, $cluster->registry_url],
        );
        Queue::assertPushed(InstallClusterParts::class);

        (new TearDownCluster($cluster))->handle(app(Runner::class), app(ClusterProviders::class));

        $this->assertSame('destroy', $this->tofu->ran[4]['args'][0]);
        $this->assertNull(Cluster::query()->find($cluster->id));
    }

    /** @return array{name: string, region: string, node_size: string, min_nodes: int, max_nodes: int} */
    private function form(): array
    {
        return ['name' => 'mars-prod', 'region' => 'mars1', 'node_size' => 'tiny', 'min_nodes' => 1, 'max_nodes' => 2];
    }
}

final class FakeCloudCluster implements ClusterProvider
{
    public function key(): string
    {
        return 'fakecloud';
    }

    public function label(): string
    {
        return 'FakeCloud';
    }

    public function credential(): string
    {
        return 'fakecloud';
    }

    public function regions(): array
    {
        return ['mars1' => 'Mars'];
    }

    public function nodeSizes(): array
    {
        return ['tiny' => 'Tiny'];
    }

    public function maxNodes(): int
    {
        return 2;
    }

    public function pricingUrl(): string
    {
        return 'https://fakecloud.example/pricing';
    }

    public function stack(Cluster $cluster): Stack
    {
        $token = ProviderCredential::query()->where('provider', 'fakecloud')->firstOrFail()->token;

        return new Stack('fakecloud/cluster', "cluster-{$cluster->id}", [
            'region' => $cluster->region,
            'node_size' => $cluster->node_size,
        ], ['FAKECLOUD_TOKEN' => $token]);
    }

    public function attributes(array $outputs): array
    {
        return [
            'kubeconfig' => $outputs['kubeconfig'],
            'provider_cluster_id' => $outputs['cluster_id'],
            'provider_vpc_id' => $outputs['vpc_id'],
            'registry_url' => $outputs['endpoint'],
            'registry_username' => 'user',
            'registry_password' => 'pass',
            'registry_insecure' => false,
        ];
    }
}
