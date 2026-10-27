<?php

namespace Tests\Feature\Jobs;

use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\FailStuckDeployments;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FailStuckDeploymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_building_and_deploying_deployments_after_70_quiet_minutes(): void
    {
        $environment = Environment::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $building = Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Building]);
        $deploying = Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Deploying]);
        $queued = Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Queued]);
        $live = Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Live]);
        $this->travelTo(Carbon::parse('2026-10-23 10:02:00'));
        $recent = Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Building]);

        $this->travelTo(Carbon::parse('2026-10-23 11:11:00'));
        FailStuckDeployments::dispatchSync();

        $this->assertSame(
            [DeploymentStatus::Failed, DeploymentStatus::Failed, DeploymentStatus::Queued, DeploymentStatus::Live, DeploymentStatus::Building],
            [$building->fresh()->status, $deploying->fresh()->status, $queued->fresh()->status, $live->fresh()->status, $recent->fresh()->status],
        );
        $this->assertSame("2026-10-23T11:11:00Z The worker stopped during this deployment.\n", $building->fresh()->log);
        $this->assertSame('2026-10-23 11:11:00', $deploying->fresh()->finished_at->toDateTimeString());
        $this->assertNull($recent->fresh()->log);
    }

    public function test_the_scheduler_runs_it_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === FailStuckDeployments::class);

        $this->assertSame('* * * * *', $event?->expression);
    }
}
