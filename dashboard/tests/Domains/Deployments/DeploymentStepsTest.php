<?php

namespace Tests\Domains\Deployments;

use App\Domains\Deployments\DeploymentLog;
use App\Domains\Deployments\DeploymentSteps;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Enums\DeploymentStep;
use App\Domains\Deployments\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeploymentStepsTest extends TestCase
{
    use RefreshDatabase;

    private function deployment(DeploymentStatus $status, ?string $log): Deployment
    {
        return Deployment::factory()->create([
            'status' => $status,
            'log' => $log,
            'started_at' => Carbon::parse('2026-10-23T12:00:00Z'),
            'finished_at' => $status->isRunning() ? null : Carbon::parse('2026-10-23T12:00:09Z'),
        ]);
    }

    public function test_a_running_deployment_has_finished_steps_and_one_running_step_with_offsets(): void
    {
        $log = "2026-10-23T12:00:00Z \x1epreparing_build\n".
            "2026-10-23T12:00:00Z Cloning\n".
            "2026-10-23T12:00:03Z \x1erunning_build\n".
            "2026-10-23T12:00:04Z #1 load\n".
            "2026-10-23T12:00:05Z #2 compile\n";

        $steps = DeploymentSteps::of($this->deployment(DeploymentStatus::BUILDING, $log));

        $this->assertSame([
            [
                'key' => 'preparing_build', 'title' => 'Preparing build environment', 'phase' => 'build', 'status' => 'finished',
                'started_at' => '2026-10-23T12:00:00Z', 'finished_at' => '2026-10-23T12:00:03Z',
                'lines' => [['offset' => 0, 'text' => 'Cloning']],
            ],
            [
                'key' => 'running_build', 'title' => 'Running build commands', 'phase' => 'build', 'status' => 'running',
                'started_at' => '2026-10-23T12:00:03Z', 'finished_at' => null,
                'lines' => [['offset' => 4, 'text' => '#1 load'], ['offset' => 5, 'text' => '#2 compile']],
            ],
        ], $steps);
    }

    public function test_a_failed_deployment_fails_its_last_step_and_skips_the_steps_after_it(): void
    {
        $log = "2026-10-23T12:00:00Z \x1epreparing_build\n".
            "2026-10-23T12:00:01Z \x1erunning_build\n".
            "2026-10-23T12:00:09Z The deployment failed. buildctl exited with code 1.\n";

        $steps = DeploymentSteps::of($this->deployment(DeploymentStatus::FAILED, $log));

        $this->assertSame(
            ['finished', 'failed', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped'],
            array_column($steps, 'status'),
        );
        $this->assertSame('2026-10-23T12:00:09Z', $steps[1]['finished_at']);
        $this->assertSame([['offset' => 9, 'text' => 'The deployment failed. buildctl exited with code 1.']], $steps[1]['lines']);
        $this->assertSame(['deploy', 'deploy', 'deploy', 'deploy'], array_column(array_slice($steps, 3), 'phase'));
        $this->assertSame([], $steps[6]['lines']);
        $this->assertNull($steps[6]['started_at']);
    }

    public function test_a_live_deployment_has_only_finished_steps(): void
    {
        $log = "2026-10-23T12:00:00Z \x1erouting_traffic\n2026-10-23T12:00:09Z The deployment is live.\n";

        $steps = DeploymentSteps::of($this->deployment(DeploymentStatus::LIVE, $log));

        $this->assertSame(['finished'], array_column($steps, 'status'));
        $this->assertSame('2026-10-23T12:00:09Z', $steps[0]['finished_at']);
    }

    public function test_a_log_without_markers_is_one_step_without_a_key(): void
    {
        $steps = DeploymentSteps::of($this->deployment(DeploymentStatus::SUPERSEDED, "2026-10-23T12:00:00Z Cloning\n2026-10-23T12:00:02Z Done\n"));

        $this->assertSame([[
            'key' => null, 'title' => 'Log', 'phase' => null, 'status' => 'finished',
            'started_at' => '2026-10-23T12:00:00Z', 'finished_at' => '2026-10-23T12:00:09Z',
            'lines' => [['offset' => 0, 'text' => 'Cloning'], ['offset' => 2, 'text' => 'Done']],
        ]], $steps);
    }

    public function test_an_empty_log_has_no_steps(): void
    {
        $this->assertSame([], DeploymentSteps::of($this->deployment(DeploymentStatus::QUEUED, null)));
    }

    public function test_a_build_line_that_looks_like_a_marker_does_not_start_a_step(): void
    {
        $deployment = $this->deployment(DeploymentStatus::BUILDING, null);
        $log = DeploymentLog::for($deployment);

        $log->step(DeploymentStep::RUNNING_BUILD);
        $log->chunk("\x1epushing_application\n#4 \x1erouting_traffic\n");

        $steps = DeploymentSteps::of($deployment->fresh());

        $this->assertSame(['running_build'], array_column($steps, 'key'));
        $this->assertSame(['pushing_application', '#4 routing_traffic'], array_column($steps[0]['lines'], 'text'));
    }
}
