<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\SleepEnvironment;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class SleepEnvironmentTest extends TestCase
{
    use BuildsEnvironments;
    use RefreshDatabase;

    public function test_an_idle_environment_is_marked_as_sleeping_and_scales_down(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::LIVE]);

        app(SleepEnvironment::class)->execute($environment);

        $this->assertNotNull($environment->fresh()->slept_at);
        $this->assertSame(['environment.slept', null, ['sleep_after_minutes' => 15]], [AuditEvent::sole()->action, AuditEvent::sole()->user_id, AuditEvent::sole()->context]);
        Queue::assertPushed(ScaleEnvironment::class, fn (ScaleEnvironment $job) => $job->environment->is($environment));
    }

    public function test_an_environment_with_a_running_deployment_stays_awake(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::LIVE]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::BUILDING]);

        app(SleepEnvironment::class)->execute($environment);

        $this->assertNull($environment->fresh()->slept_at);
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_a_sleeping_environment_does_not_sleep_again(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        $environment->forceFill(['slept_at' => now()->subHour()])->save();

        app(SleepEnvironment::class)->execute($environment);

        $this->assertSame(now()->subHour()->toDateTimeString(), $environment->fresh()->slept_at->toDateTimeString());
        Queue::assertNothingPushed();
    }
}
