<?php

namespace Tests\Feature\GitHub;

use App\Domains\Applications\Models\Application;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\Models\GithubApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushWebhookTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private const SHA = 'b1b2b3b4b5b6b7b8b9b0b1b2b3b4b5b6b7b8b9b0';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunDeployment::class]);
        $this->makeGitHubApp();
    }

    public function test_the_signature_must_match_before_anything_happens(): void
    {
        $this->makeEnvironments();
        $push = $this->push('refs/heads/main');

        $this->postWebhook('push', $push, secret: 'wrong-secret')->assertStatus(401);
        $this->postWebhook('push', $push, secret: null)->assertStatus(401);
        $this->assertSame(0, Deployment::count());

        $this->postWebhook('push', $push)->assertStatus(202)->assertCookieMissing(config('session.cookie'));
        $this->assertSame(2, Deployment::count());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_there_is_no_valid_signature_without_a_github_app(): void
    {
        $this->makeEnvironments();
        GithubApp::query()->delete();

        $this->postWebhook('push', $this->push('refs/heads/main'))->assertStatus(401);
        $this->assertSame(0, Deployment::count());
    }

    public function test_a_push_deploys_the_head_commit_to_each_permanent_auto_deploy_environment_of_the_branch(): void
    {
        $this->makeEnvironments();

        $this->postWebhook('push', $this->push('refs/heads/main'))->assertStatus(202);

        $this->assertSame(
            [['shop', 'production', self::SHA, null], ['shop-copy', 'production', self::SHA, null]],
            Deployment::query()->orderBy('id')->with('environment.application')->get()
                ->map(fn (Deployment $d) => [$d->environment->application->name, $d->environment->name, $d->commit_sha, $d->triggered_by])
                ->all(),
        );
        Queue::assertPushedOn('builds', RunDeployment::class);
    }

    public function test_branch_deletions_tags_and_redeliveries_deploy_nothing(): void
    {
        $this->makeEnvironments();

        $this->postWebhook('push', [...$this->push('refs/heads/main'), 'deleted' => true, 'after' => str_repeat('0', 40)], delivery: 'd-1')->assertStatus(202);
        $this->postWebhook('push', $this->push('refs/tags/v1.0.0'), delivery: 'd-2')->assertStatus(202);
        $this->assertSame(0, Deployment::count());

        $this->postWebhook('push', $this->push('refs/heads/staging'), delivery: 'd-3')->assertStatus(202);
        $this->postWebhook('push', $this->push('refs/heads/staging'), delivery: 'd-3')->assertStatus(200);
        $this->assertSame(['staging'], Deployment::query()->with('environment')->get()->map(fn (Deployment $d) => $d->environment->name)->all());
    }

    private function makeEnvironments(): void
    {
        $shop = Application::factory()->create(['name' => 'shop', 'repository' => 'acme/shop']);
        $copy = Application::factory()->create(['name' => 'shop-copy', 'repository' => 'https://github.com/Acme/Shop.git']);
        $other = Application::factory()->create(['name' => 'blog', 'repository' => 'acme/blog']);

        Environment::factory()->for($shop)->create(['name' => 'production', 'branch' => 'main']);
        Environment::factory()->for($shop)->create(['name' => 'staging', 'branch' => 'staging']);
        Environment::factory()->for($shop)->create(['name' => 'manual', 'branch' => 'main', 'auto_deploy' => false]);
        Environment::factory()->for($shop)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PreviewTemplate, 'branch' => 'main']);
        Environment::factory()->for($shop)->create(['name' => 'pr-3', 'kind' => EnvironmentKind::Preview, 'branch' => 'main', 'pr_number' => 3]);
        Environment::factory()->for($copy)->create(['name' => 'production', 'branch' => 'main']);
        Environment::factory()->for($other)->create(['name' => 'production', 'branch' => 'main']);
    }

    /** @return array<string, mixed> */
    private function push(string $ref): array
    {
        return [
            'ref' => $ref,
            'before' => str_repeat('a', 40),
            'after' => self::SHA,
            'deleted' => false,
            'repository' => ['full_name' => 'acme/shop'],
        ];
    }
}
