<?php

namespace Tests\Http\Controllers;

use App\Domains\Deployments\Actions\StartDeployment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class DeploymentControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const SHA = 'cccccccccccccccccccccccccccccccccccccccc';

    public function test_the_list_shows_newest_first_with_who_and_when(): void
    {
        $environment = $this->makeEnvironment();
        $dev = User::factory()->create(['name' => 'Dev']);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::SUPERSEDED]);
        Deployment::factory()->for($environment)->create([
            'status' => DeploymentStatus::LIVE,
            'triggered_by' => $dev->id,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($dev)->get("/environments/{$environment->id}/deployments")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/deployments')
                ->has('deployments', 2)
                ->where('deployments.0.status', 'live')
                ->where('deployments.0.triggered_by', 'Dev')
                ->where('deployments.1.triggered_by', null)
                ->missing('deployments.0.log'));
    }

    public function test_a_deployment_page_has_the_steps_and_the_warning(): void
    {
        $deployment = Deployment::factory()->for($this->makeEnvironment())->create([
            'status' => DeploymentStatus::BUILDING,
            'started_at' => '2026-10-23T10:00:00Z',
            'log' => "2026-10-23T10:00:00Z \x1epreparing_build\n2026-10-23T10:00:00Z Cloning\n2026-10-23T10:00:05Z \x1erunning_build\n2026-10-23T10:00:06Z Building\n",
            'warning' => 'The health check was slow.',
        ]);

        $this->actingAs(User::factory()->create())->get("/deployments/{$deployment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('deployments/show')
                ->where('deployment.status', 'building')
                ->where('deployment.warning', 'The health check was slow.')
                ->missing('deployment.log')
                ->has('steps', 2)
                ->where('steps.0.title', 'Preparing build environment')
                ->where('steps.0.status', 'finished')
                ->where('steps.0.lines', [['offset' => 0, 'text' => 'Cloning']])
                ->where('steps.1.status', 'running')
                ->where('steps.1.lines', [['offset' => 6, 'text' => 'Building']])
                ->where('environment.name', 'production'));
    }

    public function test_a_queued_deployment_names_the_build_it_waits_for(): void
    {
        $shop = $this->makeEnvironment();
        $running = Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::BUILDING]);
        Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::QUEUED]);
        $mine = Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::QUEUED]);
        $user = User::factory()->create();

        $this->actingAs($user)->get("/deployments/{$mine->id}")
            ->assertInertia(fn (Assert $page) => $page->where('waiting_for', [
                'id' => $running->id,
                'application' => $shop->application->name,
                'environment' => 'production',
                'queued_before' => 1,
            ]));

        $this->actingAs($user)->get("/deployments/{$running->id}")
            ->assertInertia(fn (Assert $page) => $page->where('waiting_for', null));
    }

    public function test_a_deployment_without_a_log_has_no_steps(): void
    {
        $deployment = Deployment::factory()->for($this->makeEnvironment())->create();

        $this->actingAs(User::factory()->create())->get("/deployments/{$deployment->id}")
            ->assertInertia(fn (Assert $page) => $page->where('steps', []));
    }

    public function test_a_developer_cannot_deploy_a_protected_environment(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(true);

        $this->actingAs(User::factory()->create())->post("/environments/{$environment->id}/deployments")->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, Deployment::count());
    }

    public function test_a_developer_deploys_an_unprotected_environment_and_lands_on_the_deployment(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $developer = User::factory()->create();
        $deployment = Deployment::factory()->for($environment)->create();

        $this->mock(StartDeployment::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($given, User $by, $sha = null) => $given->is($environment) && $by->is($developer) && $sha === null)
            ->andReturn($deployment);

        $this->actingAs($developer)->post("/environments/{$environment->id}/deployments")->assertRedirect("/deployments/{$deployment->id}");
    }

    public function test_an_admin_deploys_production_and_a_queued_deployment_starts(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";
        $environment = $this->makeEnvironment(true);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post("/environments/{$environment->id}/deployments")->assertRedirect();

        $deployment = Deployment::sole();
        $this->assertSame([DeploymentStatus::QUEUED, self::SHA, $admin->id], [$deployment->status, $deployment->commit_sha, $deployment->triggered_by]);
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_a_missing_branch_comes_back_as_an_error(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = '';
        $environment = $this->makeEnvironment(false);

        $this->actingAs(User::factory()->create())->from('/back')->post("/environments/{$environment->id}/deployments")
            ->assertRedirect('/back')
            ->assertSessionHasErrors(['deploy' => 'We could not read the branch main. Check the branch name in the settings.']);

        $this->assertSame(0, Deployment::count());
    }

    public function test_a_deploy_key_that_github_refuses_comes_back_as_the_step_that_fixes_it(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result(errorOutput: "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n", exitCode: 128)]);
        $environment = $this->makeEnvironment(false);
        $environment->application->update(['deploy_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nkey\n-----END OPENSSH PRIVATE KEY-----\n"]);

        $this->actingAs(User::factory()->create())->from('/back')->post("/environments/{$environment->id}/deployments")
            ->assertRedirect('/back')
            ->assertSessionHasErrors(['deploy' => 'GitHub refused the deploy key. Add the public key to the repository under Settings, Deploy keys.']);

        $this->assertSame(0, Deployment::count());
        Queue::assertNothingPushed();
    }
}
