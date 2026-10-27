<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Jobs\InstallClusterParts;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClusterControllerTest extends TestCase
{
    use RefreshDatabase;

    private const KUBECONFIG = "apiVersion: v1\nusers:\n- user:\n    token: KUBE_TOKEN_SECRET\n";

    private FakeKubectl $kubectl;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->kubectl = FakeKubectl::install();
        $this->kubectl->respond('auth can-i', new KubectlResult(0, "yes\n"));
    }

    public function test_only_the_owner_can_connect_reinstall_or_disconnect(): void
    {
        $cluster = $this->cluster();

        foreach ([User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/clusters/create')->assertForbidden();
            $this->actingAs($user)->post('/clusters', $this->form())->assertForbidden();
            $this->actingAs($user)->post("/clusters/{$cluster->id}/reinstall")->assertForbidden();
            $this->actingAs($user)->delete("/clusters/{$cluster->id}")->assertForbidden();
            $this->actingAs($user)->get("/clusters/{$cluster->id}")->assertInertia(fn (Assert $page) => $page
                ->where('can', ['reinstall' => false, 'disconnect' => false]));
            $this->actingAs($user)->get('/clusters')->assertInertia(fn (Assert $page) => $page->where('canConnect', false));
        }

        $this->assertSame(1, Cluster::query()->count());
        $this->assertSame(ClusterStatus::Ready, $cluster->fresh()->status);
        $this->assertSame([], $this->kubectl->calls);
        Queue::assertNothingPushed();
    }

    public function test_the_owner_connects_a_cluster_and_lands_on_its_page(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get('/clusters/create')->assertInertia(fn (Assert $page) => $page->component('clusters/create'));

        $response = $this->actingAs($owner)->post('/clusters', [...$this->form(), 'registry_url' => 'HTTPS://Registry.Example.com/team/']);

        $cluster = Cluster::query()->where('name', 'fra1')->sole();
        $response->assertRedirect("/clusters/{$cluster->id}");
        $this->assertSame(['registry.example.com/team', false, ClusterStatus::Installing], [$cluster->registry_url, $cluster->registry_insecure, $cluster->status]);
        Queue::assertPushed(InstallClusterParts::class);
    }

    public function test_a_refused_kubeconfig_comes_back_as_a_form_error_and_is_not_kept_in_the_session(): void
    {
        $this->kubectl->respond('version', new KubectlResult(1, '', 'Unable to connect to the server: EOF'));

        $this->actingAs(User::factory()->owner()->create())
            ->from('/clusters/create')
            ->post('/clusters', $this->form())
            ->assertRedirect('/clusters/create')
            ->assertSessionHasErrors(['kubeconfig' => 'Opencloud cannot reach the cluster with this kubeconfig. kubectl said: Unable to connect to the server: EOF']);

        $this->assertSame(['fra1', 'registry.example.com', 'robot'], array_values(array_intersect_key(session()->getOldInput(), array_flip(['name', 'registry_url', 'registry_username']))));
        $this->assertArrayNotHasKey('kubeconfig', session()->getOldInput());
        $this->assertArrayNotHasKey('registry_password', session()->getOldInput());
        $this->assertSame(0, Cluster::query()->count());
    }

    public function test_the_cluster_page_shows_the_state_and_the_applications_but_never_the_secrets(): void
    {
        $cluster = $this->cluster(['status' => ClusterStatus::Failed, 'error' => 'Caddy did not start in 15 minutes.']);
        $application = Application::factory()->for($cluster)->create(['name' => 'shop', 'repository' => 'https://github.com/acme/shop']);
        $datastore = Datastore::factory()->for($cluster)->create(['kind' => 'mysql']);
        $owner = User::factory()->owner()->create();

        $show = $this->actingAs($owner)->get("/clusters/{$cluster->id}")->assertOk();
        $index = $this->actingAs($owner)->get('/clusters')->assertOk();

        $show->assertInertia(fn (Assert $page) => $page
            ->component('clusters/show')
            ->where('cluster', [
                'id' => $cluster->id,
                'name' => 'fra1',
                'kind' => 'connected',
                'status' => 'failed',
                'error' => 'Caddy did not start in 15 minutes.',
                'ingress_ip' => '203.0.113.20',
                'parts_version' => '1.4.0',
                'registry_url' => 'registry.example.com',
                'registry_insecure' => false,
            ])
            ->where('applications', [['id' => $application->id, 'name' => 'shop', 'repository' => 'https://github.com/acme/shop']])
            ->where('datastores', [['id' => $datastore->id, 'kind' => 'mysql']])
            ->where('can', ['reinstall' => true, 'disconnect' => true]));
        $index->assertInertia(fn (Assert $page) => $page->where('canConnect', true));

        foreach ([$show, $index] as $response) {
            $this->assertStringNotContainsString('KUBE_TOKEN_SECRET', $response->getContent());
            $this->assertStringNotContainsString('REGISTRY_PASSWORD_SECRET', $response->getContent());
        }
    }

    public function test_the_local_cluster_page_offers_no_reinstall_or_disconnect(): void
    {
        $local = $this->cluster(['kind' => ClusterKind::Local, 'name' => 'local']);

        $this->actingAs(User::factory()->owner()->create())->get("/clusters/{$local->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can', ['reinstall' => false, 'disconnect' => false]));
    }

    public function test_reinstall_and_disconnect_go_through_the_actions(): void
    {
        $owner = User::factory()->owner()->create();
        $busy = $this->cluster();
        Application::factory()->for($busy)->create();
        $idle = $this->cluster(['name' => 'ams3']);

        $this->actingAs($owner)->post("/clusters/{$busy->id}/reinstall")->assertRedirect("/clusters/{$busy->id}");
        $this->assertSame(ClusterStatus::Installing, $busy->fresh()->status);

        $this->actingAs($owner)->from("/clusters/{$busy->id}")->delete("/clusters/{$busy->id}", ['remove_parts' => true])
            ->assertRedirect("/clusters/{$busy->id}")
            ->assertSessionHasErrors(['cluster' => 'This cluster still runs 1 applications and 0 datastores. Remove them first.']);

        $this->actingAs($owner)->delete("/clusters/{$idle->id}", ['remove_parts' => true])->assertRedirect('/clusters');
        $this->assertNull($idle->fresh());
        $this->assertSame(
            [['delete', 'namespace', 'opencloud', '--ignore-not-found', '--wait=false'], ['delete', 'namespace', 'oc-datastores', '--ignore-not-found', '--wait=false']],
            array_column($this->kubectl->calls, 'args'),
        );
        $this->assertNotNull(DB::table('clusters')->find($busy->id));
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return [
            'name' => 'fra1',
            'kubeconfig' => self::KUBECONFIG,
            'registry_url' => 'registry.example.com',
            'registry_username' => 'robot',
            'registry_password' => 'REGISTRY_PASSWORD_SECRET',
            'registry_insecure' => false,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function cluster(array $attributes = []): Cluster
    {
        return Cluster::factory()->create([
            'name' => 'fra1',
            'kubeconfig' => self::KUBECONFIG,
            'status' => ClusterStatus::Ready,
            'ingress_ip' => '203.0.113.20',
            'parts_version' => '1.4.0',
            'registry_url' => 'registry.example.com',
            'registry_username' => 'robot',
            'registry_password' => 'REGISTRY_PASSWORD_SECRET',
            ...$attributes,
        ]);
    }
}
