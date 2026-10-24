<?php

namespace Tests\Feature\Http;

use App\Models\AuditEvent;
use App\Models\Process;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProcessControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    public function test_the_canvas_lists_the_processes_in_the_order_they_were_added(): void
    {
        $environment = $this->makeEnvironment();
        $worker = $environment->processes()->create(['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 2, 'exclusive' => false]);
        $scheduler = $environment->processes()->create(['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true]);

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('processes', [
                ['id' => $worker->id, 'name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 2, 'exclusive' => false],
                ['id' => $scheduler->id, 'name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true],
            ]));
    }

    public function test_a_developer_adds_a_process_to_an_unprotected_environment_but_not_to_a_protected_one(): void
    {
        $production = $this->makeEnvironment(true, 'production');
        $staging = $this->makeEnvironment(false, 'staging');
        $developer = User::factory()->create();
        $horizon = ['name' => 'horizon', 'command' => 'php artisan horizon', 'instances' => 2, 'exclusive' => false];

        $this->actingAs($developer)->post("/environments/{$production->id}/processes", $horizon)->assertForbidden();
        $this->actingAs($developer)->from('/back')->post("/environments/{$staging->id}/processes", $horizon)->assertRedirect('/back');

        $this->assertSame(
            [[$staging->id, 'horizon', 'php artisan horizon', 2, false]],
            Process::all()->map(fn (Process $p) => [$p->environment_id, $p->name, $p->command, $p->instances, $p->exclusive])->all(),
        );
        $this->assertSame(['process.added', $developer->id], [AuditEvent::sole()->action, AuditEvent::sole()->user_id]);
    }

    public function test_a_bad_process_comes_back_with_plain_messages(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $environment->processes()->create(['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false]);
        $developer = User::factory()->create();

        $this->actingAs($developer)
            ->post("/environments/{$environment->id}/processes", ['name' => 'cron', 'command' => 'php artisan schedule:work', 'instances' => 3, 'exclusive' => true])
            ->assertSessionHasErrors(['instances' => 'An exclusive process runs exactly one instance.']);
        $this->actingAs($developer)
            ->post("/environments/{$environment->id}/processes", ['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false])
            ->assertSessionHasErrors(['name' => 'This environment already has a process with the name worker.']);

        $this->assertSame(['worker'], $environment->processes()->pluck('name')->all());
    }

    public function test_an_admin_changes_and_removes_a_process_of_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment(true);
        $worker = $environment->processes()->create(['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs(User::factory()->create())->patch("/processes/{$worker->id}", ['name' => 'worker', 'command' => 'x', 'instances' => 1, 'exclusive' => false])->assertForbidden();
        $this->actingAs(User::factory()->create())->delete("/processes/{$worker->id}")->assertForbidden();

        $this->actingAs($admin)->patch("/processes/{$worker->id}", ['name' => 'worker', 'command' => 'php artisan queue:work --queue=high', 'instances' => 4, 'exclusive' => false])->assertRedirect();
        $this->assertSame(['php artisan queue:work --queue=high', 4], [$worker->fresh()->command, $worker->fresh()->instances]);

        $this->actingAs($admin)->delete("/processes/{$worker->id}")->assertRedirect();
        $this->assertSame(0, Process::count());
        $this->assertSame(['process.updated', 'process.removed'], AuditEvent::orderBy('id')->pluck('action')->all());
    }
}
