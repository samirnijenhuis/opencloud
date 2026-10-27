<?php

namespace Tests\Feature\Environments;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Actions\DisableDeployHook;
use App\Domains\Deployments\Actions\EnableDeployHook;
use App\Domains\Deployments\Actions\RegenerateDeployHook;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\Fakes\FakeProcesses;
use Tests\Feature\Http\BuildsEnvironments;
use Tests\TestCase;

class DeployHookTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const SHA = 'cccccccccccccccccccccccccccccccccccccccc';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";
        $this->owner = User::factory()->owner()->create();
    }

    public function test_enable_disable_and_regenerate_keep_one_encrypted_token_and_record_each_change(): void
    {
        $environment = $this->makeEnvironment();

        app(EnableDeployHook::class)->handle($environment, $this->owner);
        $first = $environment->fresh()->deploy_hook_token;
        app(EnableDeployHook::class)->handle($environment, $this->owner);
        app(DisableDeployHook::class)->handle($environment, $this->owner);
        app(EnableDeployHook::class)->handle($environment, $this->owner);
        $again = $environment->fresh()->deploy_hook_token;
        app(RegenerateDeployHook::class)->handle($environment, $this->owner);
        $fresh = $environment->fresh();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $first);
        $this->assertSame($first, $again, 'Enabling the hook again keeps the URL that CI knows.');
        $this->assertNotSame($first, $fresh->deploy_hook_token);
        $this->assertTrue($fresh->deploy_hook_enabled);
        $this->assertStringNotContainsString($fresh->deploy_hook_token, (string) DB::table('environments')->where('id', $environment->id)->value('deploy_hook_token'));
        $this->assertArrayNotHasKey('deploy_hook_token', $fresh->toArray());
        $this->assertSame(
            ['deploy_hook.enabled', 'deploy_hook.disabled', 'deploy_hook.enabled', 'deploy_hook.regenerated'],
            AuditEvent::orderBy('id')->pluck('action')->all(),
        );
        $this->assertStringNotContainsString($first, AuditEvent::all()->toJson());
    }

    public function test_the_preview_template_gets_no_hook(): void
    {
        $template = $this->makeEnvironment(false, 'preview-template', ['kind' => EnvironmentKind::PreviewTemplate, 'branch' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(EnableDeployHook::class)->handle($template, $this->owner);
    }

    public function test_a_post_to_the_hook_starts_a_deployment_of_the_branch_head(): void
    {
        $environment = $this->makeEnvironment();
        app(EnableDeployHook::class)->handle($environment, $this->owner);

        $response = $this->postJson($this->hookPath($environment));

        $deployment = Deployment::sole();
        $response->assertStatus(202)->assertExactJson(['deployment_id' => $deployment->id, 'commit_sha' => self::SHA, 'status' => 'queued']);
        $this->assertSame([$environment->id, self::SHA, DeploymentStatus::Queued, null], [$deployment->environment_id, $deployment->commit_sha, $deployment->status, $deployment->triggered_by]);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->is($deployment));
        $this->assertSame(
            [null, ['deployment_id' => $deployment->id, 'ip' => '127.0.0.1']],
            [AuditEvent::where('action', 'deploy_hook.triggered')->sole()->user_id, AuditEvent::where('action', 'deploy_hook.triggered')->sole()->context],
        );
    }

    public function test_a_disabled_hook_and_a_wrong_token_both_answer_404(): void
    {
        $environment = $this->makeEnvironment();
        app(EnableDeployHook::class)->handle($environment, $this->owner);
        $path = $this->hookPath($environment);
        app(DisableDeployHook::class)->handle($environment, $this->owner);

        $this->postJson($path)->assertNotFound()->assertExactJson(['message' => 'No deploy hook has this URL.']);
        $this->postJson('/hooks/deploy/'.str_repeat('x', 48))->assertNotFound();
        $this->postJson('/hooks/deploy/')->assertNotFound();

        $this->assertSame(0, Deployment::count());
    }

    public function test_the_hook_allows_ten_requests_a_minute(): void
    {
        $statuses = collect(range(1, 11))->map(fn () => $this->postJson('/hooks/deploy/wrong-token')->status())->all();

        $this->assertSame([404, 404, 404, 404, 404, 404, 404, 404, 404, 404, 429], $statuses);
    }

    public function test_the_hook_route_has_no_session_and_no_csrf_check(): void
    {
        $middleware = Route::getRoutes()->getByName('hooks.deploy')->gatherMiddleware();

        $this->assertSame(['api', 'throttle:10,1,deploy-hook'], $middleware);
    }

    public function test_only_users_who_may_change_the_environment_see_the_url_or_change_the_hook(): void
    {
        $production = $this->makeEnvironment();
        $staging = Environment::factory()->for($production->application)->create(['name' => 'staging', 'is_protected' => false]);
        app(EnableDeployHook::class)->handle($production, $this->owner);
        $developer = User::factory()->create();

        $this->actingAs($this->owner)->getJson("/environments/{$production->id}/deploy-hook")
            ->assertOk()->assertExactJson(['enabled' => true, 'url' => 'http://localhost'.$this->hookPath($production)]);
        $this->actingAs($developer)->getJson("/environments/{$production->id}/deploy-hook")
            ->assertOk()->assertExactJson(['enabled' => true, 'url' => null]);
        $this->actingAs($developer)->post("/environments/{$production->id}/deploy-hook/regenerate")->assertForbidden();
        $this->actingAs($developer)->delete("/environments/{$production->id}/deploy-hook")->assertForbidden();

        $this->actingAs($developer)->post("/environments/{$staging->id}/deploy-hook")->assertRedirect();
        $this->actingAs($developer)->getJson("/environments/{$staging->id}/deploy-hook")
            ->assertOk()->assertExactJson(['enabled' => true, 'url' => 'http://localhost'.$this->hookPath($staging)]);
        $this->actingAs($developer)->delete("/environments/{$staging->id}/deploy-hook")->assertRedirect();

        $this->assertSame([true, false], [$production->fresh()->deploy_hook_enabled, $staging->fresh()->deploy_hook_enabled]);
    }

    public function test_guests_get_nothing(): void
    {
        $environment = $this->makeEnvironment();

        $this->getJson("/environments/{$environment->id}/deploy-hook")->assertUnauthorized();
        $this->postJson("/environments/{$environment->id}/deploy-hook")->assertUnauthorized();
        $this->assertFalse($environment->fresh()->deploy_hook_enabled);
    }

    private function hookPath(Environment $environment): string
    {
        return '/hooks/deploy/'.$environment->fresh()->deploy_hook_token;
    }
}
