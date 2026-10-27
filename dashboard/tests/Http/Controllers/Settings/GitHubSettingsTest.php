<?php

namespace Tests\Http\Controllers\Settings;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\GitHub\Models\GithubApp;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithGitHubApp;
use Tests\TestCase;

class GitHubSettingsTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://203.0.113.10:8000']);
        Http::preventStrayRequests();
    }

    public function test_the_owner_gets_the_manifest_form_and_others_get_403(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get('/settings/github')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('settings/github')->where('app', null)->where('status', null));
        $props = $response->viewData('page')['props'];
        $this->assertSame([
            'name' => 'opencloud-203-0-113-10',
            'url' => 'http://203.0.113.10:8000',
            'hook_attributes' => ['url' => 'http://203.0.113.10:8000/webhooks/github', 'active' => true],
            'redirect_url' => 'http://203.0.113.10:8000/settings/github/callback',
            'public' => false,
            'default_permissions' => ['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'write', 'issues' => 'write'],
            'default_events' => ['push', 'pull_request'],
        ], json_decode($props['manifest'], true));
        $this->assertSame(40, strlen($props['state']));
        $this->assertSame($props['state'], session('github_manifest_state'));

        $this->actingAs(User::factory()->admin()->create())->get('/settings/github')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/settings/github/callback?code=x&state=y')->assertForbidden();
    }

    public function test_the_callback_converts_the_code_stores_the_app_encrypted_and_never_shows_the_secrets(): void
    {
        $owner = User::factory()->owner()->create();
        $pem = self::privateKey();
        Http::fake([
            'api.github.com/app-manifests/code-123/conversions' => Http::response([
                'id' => 4242,
                'slug' => 'opencloud-203-0-113-10',
                'owner' => ['login' => 'acme'],
                'pem' => $pem,
                'webhook_secret' => 'whsec-from-github',
                'client_id' => 'Iv1.abc',
                'client_secret' => 'client-secret-xyz',
            ], 201),
            'api.github.com/app/installations' => Http::response([['account' => ['login' => 'acme'], 'repository_selection' => 'selected']]),
        ]);
        $this->actingAs($owner)->withSession(['github_manifest_state' => 'state-abc']);

        $this->get('/settings/github/callback?code=code-123&state=state-abc')->assertRedirect('/settings/github');

        $app = GithubApp::sole();
        $this->assertSame([4242, 'opencloud-203-0-113-10', 'acme', $pem, 'whsec-from-github'], [$app->app_id, $app->slug, $app->owner, $app->private_key, $app->webhook_secret]);
        $stored = json_encode(DB::table('github_apps')->first());
        $this->assertStringNotContainsString('whsec-from-github', $stored);
        $this->assertStringNotContainsString('BEGIN', $stored);
        $this->assertSame(['github_app.created', ['app_id' => 4242, 'slug' => 'opencloud-203-0-113-10', 'owner' => 'acme']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);

        $page = $this->get('/settings/github');
        $page->assertInertia(fn (Assert $page) => $page
            ->where('status', 'Opencloud is connected to the new GitHub App. Install it on your repositories next.')
            ->where('app', [
                'app_id' => 4242,
                'slug' => 'opencloud-203-0-113-10',
                'owner' => 'acme',
                'html_url' => 'https://github.com/apps/opencloud-203-0-113-10',
                'install_url' => 'https://github.com/apps/opencloud-203-0-113-10/installations/new',
                'installations' => [['account' => 'acme', 'repository_selection' => 'selected']],
                'error' => null,
            ]));
        $this->assertStringNotContainsString('whsec-from-github', $page->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $page->getContent());

        $installationsCall = Http::recorded(fn (Request $request) => $request->url() === 'https://api.github.com/app/installations')->sole()[0];
        $this->assertStringStartsWith('Bearer ey', $installationsCall->header('Authorization')[0]);
    }

    public function test_a_callback_with_a_wrong_state_never_calls_github(): void
    {
        $this->actingAs(User::factory()->owner()->create())->withSession(['github_manifest_state' => 'state-abc']);

        $this->get('/settings/github/callback?code=code-123&state=other')
            ->assertRedirect('/settings/github')
            ->assertSessionHasErrors(['github' => 'This link from GitHub is not valid. Create the GitHub App again.']);

        $this->assertSame(0, GithubApp::count());
        $this->assertCount(0, Http::recorded());
    }

    public function test_the_page_still_shows_the_app_when_github_refuses_it(): void
    {
        $this->makeGitHubApp();
        Http::fake(['api.github.com/app/installations' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->actingAs(User::factory()->owner()->create())->get('/settings/github')
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.slug', 'opencloud-dash')
                ->where('app.installations', null)
                ->where('app.error', 'Opencloud could not read the installations of the app from GitHub.'));
    }
}
