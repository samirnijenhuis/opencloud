<?php

namespace Tests\Feature\Http;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ProvisionCluster;
use App\Jobs\TearDownCluster;
use App\Models\Cluster;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreatedClusterControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_DO_TOKEN_SECRET_0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        ProviderCredential::factory()->create(['provider' => 'digitalocean', 'token' => self::TOKEN]);
    }

    public function test_only_the_owner_can_create_or_destroy_and_others_see_no_log(): void
    {
        $cluster = $this->createdCluster();

        foreach ([User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/clusters/create/digitalocean')->assertForbidden();
            $this->actingAs($user)->post('/clusters/create/digitalocean', $this->form())->assertForbidden();
            $this->actingAs($user)->delete("/clusters/{$cluster->id}/destroy", ['confirm' => 'ams3-prod'])->assertForbidden();
            $this->actingAs($user)->get("/clusters/{$cluster->id}")->assertInertia(fn (Assert $page) => $page
                ->where('created.tofu_log', null)
                ->where('created.can_destroy', false));
        }

        $this->assertSame(1, Cluster::query()->count());
        $this->assertSame(ClusterStatus::Ready, $cluster->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_the_create_page_offers_the_choices_and_the_cost_link(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/clusters/create/digitalocean')->assertInertia(fn (Assert $page) => $page
            ->component('clusters/create-provider')
            ->where('regions.0', ['value' => 'ams3', 'label' => 'Amsterdam (ams3)'])
            ->where('node_sizes.1', ['value' => 's-2vcpu-4gb', 'label' => '2 vCPU, 4 GB (s-2vcpu-4gb)'])
            ->where('max_nodes', 10)
            ->where('pricing_url', 'https://www.digitalocean.com/pricing/kubernetes')
            ->where('has_key', true));

        ProviderCredential::query()->delete();

        $this->actingAs(User::factory()->owner()->create())->get('/clusters/create/digitalocean')->assertInertia(fn (Assert $page) => $page->where('has_key', false));
    }

    public function test_the_owner_creates_a_cluster_and_lands_on_its_page(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())->post('/clusters/create/digitalocean', [...$this->form(), 'min_nodes' => '2', 'max_nodes' => '4']);

        $cluster = Cluster::query()->where('name', 'ams3-prod')->sole();
        $response->assertRedirect("/clusters/{$cluster->id}");
        $this->assertSame([ClusterKind::Created, ClusterStatus::Provisioning, 2, 4], [$cluster->kind, $cluster->status, $cluster->min_nodes, $cluster->max_nodes]);
        Queue::assertPushed(ProvisionCluster::class);
    }

    public function test_the_form_is_validated(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from('/clusters/create/digitalocean')
            ->post('/clusters/create/digitalocean', ['name' => 'Prod Cluster', 'region' => 'mars1', 'node_size' => 's-1vcpu-1gb', 'min_nodes' => 3, 'max_nodes' => 2])
            ->assertRedirect('/clusters/create/digitalocean')
            ->assertSessionHasErrors([
                'name' => 'Use lowercase letters, digits, and dashes. Start and end with a letter or a digit.',
                'region' => 'The selected region is invalid.',
                'node_size' => 'The selected node size is invalid.',
                'max_nodes' => 'The maximum must be at least the minimum.',
            ]);

        $this->assertSame(0, Cluster::query()->count());
    }

    public function test_destroy_needs_the_typed_name_then_goes_through_the_action(): void
    {
        $owner = User::factory()->owner()->create();
        $cluster = $this->createdCluster();

        $this->actingAs($owner)->from("/clusters/{$cluster->id}")->delete("/clusters/{$cluster->id}/destroy", ['confirm' => 'ams3'])
            ->assertRedirect("/clusters/{$cluster->id}")
            ->assertSessionHasErrors(['confirm' => 'Type the name of the cluster to confirm.']);
        Queue::assertNothingPushed();

        $this->actingAs($owner)->delete("/clusters/{$cluster->id}/destroy", ['confirm' => 'ams3-prod'])->assertRedirect("/clusters/{$cluster->id}");

        $this->assertSame(ClusterStatus::Destroying, $cluster->fresh()->status);
        Queue::assertPushed(TearDownCluster::class);
    }

    public function test_no_page_prop_holds_the_kubeconfig_the_token_or_a_password(): void
    {
        $owner = User::factory()->owner()->create();
        $cluster = $this->createdCluster(['tofu_log' => "2026-10-23T00:00:00Z Apply complete!\n"]);

        $show = $this->actingAs($owner)->get("/clusters/{$cluster->id}")->assertInertia(fn (Assert $page) => $page
            ->where('created', [
                'provider' => 'DigitalOcean',
                'region' => 'ams3',
                'node_size' => 's-2vcpu-4gb',
                'min_nodes' => 1,
                'max_nodes' => 3,
                'tofu_log' => "2026-10-23T00:00:00Z Apply complete!\n",
                'can_destroy' => true,
            ])
            ->where('cluster.registry_url', 'registry.digitalocean.com/acme'));
        $responses = [
            $show,
            $this->actingAs($owner)->get('/clusters'),
            $this->actingAs($owner)->get('/clusters/create/digitalocean'),
            $this->actingAs($owner)->get("/clusters/{$cluster->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'clusters/show', 'X-Inertia-Partial-Data' => 'cluster,created']),
        ];

        foreach ($responses as $response) {
            $response->assertOk();

            foreach (['SA_TOKEN_SECRET', self::TOKEN, 'REGISTRY_PASSWORD_SECRET', 'REGISTRY_USER_SECRET'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    /** @return array<string, mixed> */
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
            'status' => ClusterStatus::Ready,
            'kubeconfig' => "apiVersion: v1\nusers:\n- user:\n    token: SA_TOKEN_SECRET\n",
            'registry_url' => 'registry.digitalocean.com/acme',
            'registry_username' => 'REGISTRY_USER_SECRET',
            'registry_password' => 'REGISTRY_PASSWORD_SECRET',
            ...$attributes,
        ]);
    }
}
