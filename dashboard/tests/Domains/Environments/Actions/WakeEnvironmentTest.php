<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Actions\WakeEnvironment;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class WakeEnvironmentTest extends TestCase
{
    use BuildsEnvironments;
    use RefreshDatabase;

    public function test_a_sleeping_environment_is_marked_as_awake_and_scales_up(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $user = User::factory()->create();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        $environment->forceFill(['slept_at' => now()->subHour()])->save();

        app(WakeEnvironment::class)->execute($environment, $user);

        $environment->refresh();
        $this->assertSame([null, now()->toDateTimeString()], [$environment->slept_at, $environment->woke_at->toDateTimeString()]);
        $this->assertSame(['environment.woke', $user->id], [AuditEvent::sole()->action, AuditEvent::sole()->user_id]);
        Queue::assertPushed(ScaleEnvironment::class, fn (ScaleEnvironment $job) => $job->environment->is($environment));
    }

    public function test_a_model_from_before_the_sleep_still_wakes_the_environment(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        $before = $environment->fresh();
        $environment->forceFill(['slept_at' => now()])->save();

        app(WakeEnvironment::class)->execute($before, null);

        $this->assertNull($environment->fresh()->slept_at);
        Queue::assertPushed(ScaleEnvironment::class);
    }

    public function test_a_call_while_the_pods_start_sends_the_scale_again_without_a_second_wake(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        $environment->forceFill(['woke_at' => now()->subMinutes(4)])->save();

        $this->assertTrue(app(WakeEnvironment::class)->execute($environment, null)->isWaking());

        $this->assertSame(0, AuditEvent::count());
        Queue::assertPushed(ScaleEnvironment::class, fn (ScaleEnvironment $job) => $job->environment->is($environment));
    }

    public function test_five_minutes_after_the_wake_a_call_sends_nothing(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment(attributes: ['sleep_after_minutes' => 15]);
        $environment->forceFill(['woke_at' => now()->subMinutes(6)])->save();

        $this->assertFalse(app(WakeEnvironment::class)->execute($environment, null)->isWaking());

        Queue::assertNothingPushed();
    }

    public function test_an_awake_environment_changes_nothing(): void
    {
        Queue::fake();
        $environment = $this->makeEnvironment();

        app(WakeEnvironment::class)->execute($environment, null);

        $this->assertNull($environment->fresh()->woke_at);
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }
}
