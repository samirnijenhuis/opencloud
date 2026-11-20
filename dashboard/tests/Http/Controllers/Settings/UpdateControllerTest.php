<?php

namespace Tests\Http\Controllers\Settings;

use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Members\Models\User;
use App\Domains\Upgrades\Jobs\CheckLatestRelease;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private Filesystem $data;

    protected function setUp(): void
    {
        parent::setUp();

        config(['opencloud.version' => 'v0.5.3']);
        $this->data = Storage::fake('opencloud-data');
        config(['opencloud.data_path' => $this->data->path('')]);
        Cache::put(CheckLatestRelease::CACHE_KEY, 'v0.6.0');
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $this->kubectl = FakeKubectl::install();
        $this->kubectl->respond('get deployment dashboard', new KubectlResult(0, json_encode(['spec' => ['template' => ['spec' => ['containers' => [
            ['name' => 'web', 'image' => 'ghcr.io/opencloud-dev/opencloud:v0.5.3'],
        ]]]]])));
    }

    public function test_only_the_owner_sees_and_starts_an_update(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/settings/updates')->assertForbidden();
        $this->actingAs($admin)->post('/settings/updates', ['target' => 'v0.6.0'])->assertForbidden();
        $this->actingAs($admin)->get('/settings/updates/progress')->assertForbidden();
        $this->actingAs($admin)->getJson('/settings/updates/status')->assertForbidden();
        $this->assertSame([], $this->kubectl->appliedManifests());
        $this->assertFalse($this->data->exists('upgrade.json'));
        $this->actingAs($admin)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('can.upgrade_opencloud', false));
    }

    public function test_the_page_shows_the_running_version_the_newer_release_and_the_last_upgrade(): void
    {
        $this->data->put('upgrade.json', '{"state":"failed","from":"v0.5.2","target":"v0.5.3","message":"The installer timed out."}');

        $this->actingAs(User::factory()->owner()->create())->get('/settings/updates')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/updates')
                ->where('can.upgrade_opencloud', true)
                ->where('version', 'v0.5.3')
                ->where('latest', 'v0.6.0')
                ->where('lastUpgrade', ['state' => 'failed', 'from' => 'v0.5.2', 'target' => 'v0.5.3', 'message' => 'The installer timed out.']));
    }

    public function test_the_update_starts_and_the_browser_goes_to_the_progress_page(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/updates', ['target' => 'v0.6.0'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('updates.progress'));

        $this->assertSame(['Job'], array_column($this->kubectl->appliedManifests(), 'kind'));
        $this->assertSame('starting', json_decode($this->data->get('upgrade.json'), true)['state']);
    }

    public function test_an_update_to_a_release_that_is_not_the_newer_one_is_refused(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from('/settings/updates')
            ->post('/settings/updates', ['target' => 'v0.7.0'])
            ->assertRedirect('/settings/updates')
            ->assertSessionHasErrors(['target' => 'Opencloud cannot update to this release. Reload the page to see the latest release.']);

        $this->assertSame([], $this->kubectl->appliedManifests());
        $this->assertFalse($this->data->exists('upgrade.json'));
    }

    public function test_the_status_reports_the_running_version_and_the_state_of_the_upgrade(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->getJson('/settings/updates/status')->assertExactJson(['version' => 'v0.5.3', 'upgrade' => null]);

        $this->data->put('upgrade.json', '{"state":"running","from":"v0.5.3","target":"v0.6.0","message":"Opencloud downloads and installs v0.6.0."}');

        $this->actingAs($owner)->getJson('/settings/updates/status')->assertExactJson([
            'version' => 'v0.5.3',
            'upgrade' => ['state' => 'running', 'from' => 'v0.5.3', 'target' => 'v0.6.0', 'message' => 'Opencloud downloads and installs v0.6.0.'],
        ]);
    }

    public function test_the_progress_page_polls_the_status_route_and_loads_no_dashboard_assets(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())->get('/settings/updates/progress')->assertOk();

        $response->assertSee(json_encode(route('updates.status')), false);
        $response->assertSee(json_encode(route('updates.edit')), false);
        $response->assertDontSee('/build/', false);
    }
}
