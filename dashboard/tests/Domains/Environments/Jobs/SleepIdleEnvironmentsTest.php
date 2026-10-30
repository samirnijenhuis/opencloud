<?php

namespace Tests\Domains\Environments\Jobs;

use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use App\Domains\Environments\Jobs\SleepIdleEnvironments;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class SleepIdleEnvironmentsTest extends TestCase
{
    use BuildsEnvironments;
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ScaleEnvironment::class]);
        $this->kubectl = FakeKubectl::install();
    }

    public function test_a_preview_without_requests_sleeps_and_a_preview_with_requests_stays_awake(): void
    {
        $this->freezeSecond();
        $visited = $this->preview('pr-1', 'pr-1.example.com', liveSince: now()->subHour());
        $idle = $this->preview('pr-2', 'pr-2.example.com', liveSince: now()->subHour());
        $this->caddyCounts(['pr-1.example.com' => 5, 'pr-2.example.com' => 2]);

        // The first read has nothing to compare with, so each hostname with a count had requests.
        SleepIdleEnvironments::dispatch();
        $this->assertSame([now()->toDateTimeString(), now()->toDateTimeString()], [$visited->fresh()->last_request_at?->toDateTimeString(), $idle->fresh()->last_request_at?->toDateTimeString()]);

        $this->travel(16)->minutes();
        $this->caddyCounts(['pr-1.example.com' => 9, 'pr-2.example.com' => 2]);
        SleepIdleEnvironments::dispatch();

        $this->assertSame([null, now()->toDateTimeString()], [$visited->fresh()->slept_at, $visited->fresh()->last_request_at->toDateTimeString()]);
        $this->assertSame(now()->toDateTimeString(), $idle->fresh()->slept_at?->toDateTimeString());
        Queue::assertPushed(ScaleEnvironment::class, 1);
        Queue::assertPushed(ScaleEnvironment::class, fn (ScaleEnvironment $job) => $job->environment->is($idle));
    }

    public function test_a_counter_that_caddy_set_back_to_zero_counts_as_requests(): void
    {
        $preview = $this->preview('pr-1', 'pr-1.example.com', liveSince: now()->subHour());
        $this->caddyCounts(['pr-1.example.com' => 50]);
        SleepIdleEnvironments::dispatch();

        $this->travel(16)->minutes();
        $this->caddyCounts(['pr-1.example.com' => 1]);
        SleepIdleEnvironments::dispatch();

        $this->assertNull($preview->fresh()->slept_at);
        Queue::assertNothingPushed();
    }

    public function test_a_new_deployment_and_a_wake_count_as_activity(): void
    {
        $deployed = $this->preview('pr-1', 'pr-1.example.com', liveSince: now()->subMinutes(10));
        $woken = $this->preview('pr-2', 'pr-2.example.com', liveSince: now()->subHour());
        $woken->forceFill(['woke_at' => now()->subMinutes(10), 'last_request_at' => now()->subHour()])->save();
        $this->caddyCounts([]);

        SleepIdleEnvironments::dispatch();

        $this->assertSame([null, null], [$deployed->fresh()->slept_at, $woken->fresh()->slept_at]);
        Queue::assertNothingPushed();
    }

    public function test_a_permanent_environment_never_sleeps_until_the_user_turns_sleep_on(): void
    {
        $production = $this->makeEnvironment(attributes: ['kind' => EnvironmentKind::PERMANENT]);
        Deployment::factory()->for($production)->create(['status' => DeploymentStatus::LIVE, 'finished_at' => now()->subDay()]);
        $this->caddyCounts([]);

        SleepIdleEnvironments::dispatch();

        $this->assertNull($production->fresh()->slept_at);
        $this->assertSame([], $this->kubectl->calls);

        $production->update(['sleep_after_minutes' => 60]);
        SleepIdleEnvironments::dispatch();

        $this->assertNotNull($production->fresh()->slept_at);
    }

    public function test_nothing_sleeps_while_caddy_does_not_answer(): void
    {
        $preview = $this->preview('pr-1', 'pr-1.example.com', liveSince: now()->subHour());
        $this->kubectl->respond('exec deploy/caddy', new KubectlResult(1, '', 'error: container not found ("caddy")'));

        SleepIdleEnvironments::dispatch();

        $this->assertNull($preview->fresh()->slept_at);
        Queue::assertNothingPushed();
    }

    public function test_the_scheduler_runs_it_each_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->sole(fn (Event $event) => $event->description === SleepIdleEnvironments::class);

        $this->assertSame('* * * * *', $event->expression);
    }

    private function preview(string $name, string $hostname, mixed $liveSince): Environment
    {
        $application = isset($this->cluster) ? $this->cluster->applications()->first() : null;
        $preview = $application === null
            ? $this->makeEnvironment(name: $name, attributes: ['kind' => EnvironmentKind::PREVIEW, 'sleep_after_minutes' => 15])
            : Environment::factory()->for($application)->create(['name' => $name, 'kind' => EnvironmentKind::PREVIEW, 'sleep_after_minutes' => 15]);
        Deployment::factory()->for($preview)->create(['status' => DeploymentStatus::LIVE, 'finished_at' => $liveSince]);
        Domain::factory()->for($preview)->create(['hostname' => $hostname]);

        return $preview;
    }

    /** @param  array<string, int>  $counts */
    private function caddyCounts(array $counts): void
    {
        $lines = array_map(fn (string $host, int $count) => "caddy_http_requests_total{handler=\"reverse_proxy\",host=\"{$host}\",server=\"apps\"} {$count}", array_keys($counts), $counts);
        $this->kubectl->respond('exec deploy/caddy', new KubectlResult(0, implode("\n", ['# TYPE caddy_http_requests_total counter', ...$lines])."\n"));
    }
}
