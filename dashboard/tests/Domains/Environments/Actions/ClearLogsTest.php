<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Actions\ClearLogs;
use App\Domains\Environments\Jobs\DeleteLogLines;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClearLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_logs_stores_the_time_records_an_event_and_queues_the_delete_from_the_log_store(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $environment = Environment::factory()->create();
        $user = User::factory()->create();

        app(ClearLogs::class)->execute($environment, $user);

        $this->assertEquals(now(), $environment->fresh()->logs_cleared_at);
        $event = AuditEvent::sole();
        $this->assertSame(['environment.logs_cleared', $user->id, 'environment', $environment->id, []], [$event->action, $event->user_id, $event->subject_type, $event->subject_id, $event->context]);
        Queue::assertPushed(DeleteLogLines::class, fn (DeleteLogLines $job) => $job->environment->is($environment));
    }
}
