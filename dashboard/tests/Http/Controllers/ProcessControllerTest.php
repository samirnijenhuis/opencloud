<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class ProcessControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const HORIZON = [
        'name' => 'horizon', 'kind' => 'long_running', 'command' => 'php artisan horizon', 'schedule' => null,
        'size' => 'm', 'min_instances' => 2, 'max_instances' => 4, 'cpu_target' => 60,
    ];

    public function test_the_canvas_lists_each_process_with_its_kind_and_its_own_size_in_the_order_they_were_added(): void
    {
        $environment = $this->makeEnvironment();
        $worker = Process::factory()->for($environment)->create(['size' => Size::M, 'min_instances' => 1, 'max_instances' => 3, 'cpu_target' => 60]);
        $scheduler = Process::factory()->for($environment)->scheduled()->create(['size' => Size::XS]);

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('processes', [
                [
                    'id' => $worker->id, 'name' => 'worker', 'kind' => 'long_running', 'command' => 'php artisan queue:work', 'schedule' => null,
                    'size' => 'm', 'min_instances' => 1, 'max_instances' => 3, 'cpu_target' => 60,
                ],
                [
                    'id' => $scheduler->id, 'name' => 'scheduler', 'kind' => 'scheduled', 'command' => 'php artisan schedule:run', 'schedule' => '* * * * *',
                    'size' => 'xs', 'min_instances' => null, 'max_instances' => null, 'cpu_target' => null,
                ],
            ]));
    }

    public function test_a_developer_adds_a_process_to_production_and_to_staging(): void
    {
        $production = $this->makeEnvironment('production');
        $staging = $this->makeEnvironment('staging');
        $developer = User::factory()->create();

        $this->actingAs($developer)->from('/back')->post("/environments/{$production->id}/processes", self::HORIZON)->assertRedirect('/back');
        $this->actingAs($developer)->from('/back')->post("/environments/{$staging->id}/processes", self::HORIZON)->assertRedirect('/back');

        $this->assertSame([[$production->id, ...array_values(self::HORIZON)], [$staging->id, ...array_values(self::HORIZON)]], $this->rows());
        $this->assertSame([['process.added', $developer->id], ['process.added', $developer->id]], AuditEvent::orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->user_id])->all());
    }

    public function test_a_scheduled_process_needs_a_valid_schedule_and_ignores_the_instances_that_the_sheet_sends(): void
    {
        $environment = $this->makeEnvironment('staging');
        $developer = User::factory()->create();
        $scheduler = [
            'name' => 'scheduler', 'kind' => 'scheduled', 'command' => 'php artisan schedule:run', 'schedule' => '0 0 L * *',
            'size' => 's', 'min_instances' => 1, 'max_instances' => 1, 'cpu_target' => 70,
        ];

        $this->actingAs($developer)->post("/environments/{$environment->id}/processes", $scheduler)
            ->assertSessionHasErrors(['schedule' => 'Enter a cron schedule with five fields, for example */5 * * * * for each five minutes. Kubernetes does not accept L, W, or #.']);
        $this->assertSame([], $this->rows());

        $this->actingAs($developer)->post("/environments/{$environment->id}/processes", [...$scheduler, 'schedule' => '*/5 * * * *', 'min_instances' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertSame([[$environment->id, 'scheduler', 'scheduled', 'php artisan schedule:run', '*/5 * * * *', 's', null, null, null]], $this->rows());
    }

    public function test_a_bad_process_comes_back_with_plain_messages(): void
    {
        $environment = $this->makeEnvironment('staging');
        Process::factory()->for($environment)->create();
        $developer = User::factory()->create();

        $this->actingAs($developer)
            ->post("/environments/{$environment->id}/processes", [...self::HORIZON, 'min_instances' => 3, 'max_instances' => 2])
            ->assertSessionHasErrors(['max_instances' => 'Use a maximum that is the same as the minimum or more.']);
        $this->actingAs($developer)
            ->post("/environments/{$environment->id}/processes", [...self::HORIZON, 'kind' => 'cron'])
            ->assertSessionHasErrors(['kind' => 'Choose long-running or scheduled.']);
        $this->actingAs($developer)
            ->post("/environments/{$environment->id}/processes", [...self::HORIZON, 'name' => 'worker'])
            ->assertSessionHasErrors(['name' => 'This environment already has a process with the name worker.']);

        $this->assertSame(['worker'], $environment->processes()->pluck('name')->all());
    }

    public function test_a_developer_changes_and_removes_a_process_of_production(): void
    {
        $environment = $this->makeEnvironment();
        $worker = Process::factory()->for($environment)->create();
        $developer = User::factory()->create();
        $change = [...self::HORIZON, 'name' => 'worker', 'command' => 'php artisan queue:work --queue=high'];

        $this->actingAs($developer)->patch("/processes/{$worker->id}", $change)->assertRedirect();
        $this->assertSame(
            [ProcessKind::LONG_RUNNING, 'php artisan queue:work --queue=high', Size::M, 2, 4, 60],
            array_values($worker->fresh()->only(['kind', 'command', 'size', 'min_instances', 'max_instances', 'cpu_target'])),
        );

        $this->actingAs($developer)->delete("/processes/{$worker->id}")->assertRedirect();
        $this->assertSame(0, Process::count());
        $this->assertSame(['process.updated', 'process.removed'], AuditEvent::orderBy('id')->pluck('action')->all());
    }

    /** @return list<list<mixed>> */
    private function rows(): array
    {
        return Process::query()->orderBy('id')->get()->map(fn (Process $process) => [
            $process->environment_id, $process->name, $process->kind->value, $process->command, $process->schedule,
            $process->size->value, $process->min_instances, $process->max_instances, $process->cpu_target,
        ])->all();
    }
}
