<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Actions\AddProcess;
use App\Domains\Environments\Actions\RemoveProcess;
use App\Domains\Environments\Actions\UpdateProcess;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcessActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->environment = Environment::factory()->for(Application::factory())->create();
    }

    public function test_add_process_stores_the_trimmed_values_and_records_an_event(): void
    {
        $process = app(AddProcess::class)->handle($this->environment, $this->user, ' horizon ', '  php artisan horizon ', 2);

        $this->assertSame(
            [$this->environment->id, 'horizon', 'php artisan horizon', 2, false],
            [$process->environment_id, $process->name, $process->command, $process->instances, $process->exclusive],
        );
        $event = AuditEvent::sole();
        $this->assertSame(['process.added', $this->user->id, $process->id], [$event->action, $event->user_id, $event->subject_id]);
        $this->assertSame(
            ['environment_id' => $this->environment->id, 'name' => 'horizon', 'command' => 'php artisan horizon', 'instances' => 2, 'exclusive' => false],
            $event->context,
        );
    }

    public function test_add_process_explains_each_value_it_refuses(): void
    {
        app(AddProcess::class)->handle($this->environment, $this->user, 'worker', 'php artisan queue:work');

        $cases = [
            [['Worker', 'php artisan queue:work', 1, false], ['name' => ['Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.']]],
            [['-ssr', 'php artisan inertia:start-ssr', 1, false], ['name' => ['Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.']]],
            [[str_repeat('a', 31), 'php artisan horizon', 1, false], ['name' => ['Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.']]],
            [['web', 'php artisan serve', 1, false], ['name' => ['Opencloud uses the names web, deploy, and command. Use a different name.']]],
            [['deploy', 'php artisan migrate', 1, false], ['name' => ['Opencloud uses the names web, deploy, and command. Use a different name.']]],
            [['command', 'php artisan tinker', 1, false], ['name' => ['Opencloud uses the names web, deploy, and command. Use a different name.']]],
            [['worker', 'php artisan queue:work', 1, false], ['name' => ['This environment already has a process with the name worker.']]],
            [['reverb', '   ', 1, false], ['command' => ['Enter the command that starts the process.']]],
            [['reverb', 'php artisan reverb:start', 0, false], ['instances' => ['Use 1 to 20 instances.']]],
            [['reverb', 'php artisan reverb:start', 21, false], ['instances' => ['Use 1 to 20 instances.']]],
            [['cron', 'php artisan schedule:work', 2, true], ['instances' => ['An exclusive process runs exactly one instance.']]],
        ];

        foreach ($cases as [$arguments, $errors]) {
            try {
                app(AddProcess::class)->handle($this->environment, $this->user, ...$arguments);
                $this->fail('AddProcess accepted '.json_encode($arguments).'.');
            } catch (ValidationException $e) {
                $this->assertSame($errors, $e->errors(), json_encode($arguments));
            }
        }

        $this->assertSame(['worker'], $this->environment->processes()->pluck('name')->all());
    }

    public function test_a_process_with_the_same_name_in_another_environment_is_allowed(): void
    {
        $other = Environment::factory()->for($this->environment->application)->create(['name' => 'staging']);
        app(AddProcess::class)->handle($other, $this->user, 'worker', 'php artisan queue:work');

        $process = app(AddProcess::class)->handle($this->environment, $this->user, 'worker', 'php artisan queue:work', 1, true);

        $this->assertSame(['worker', true], [$process->name, $process->exclusive]);
    }

    public function test_update_process_records_only_the_changes(): void
    {
        $process = $this->environment->processes()->create(['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false]);

        app(UpdateProcess::class)->handle($process, $this->user, 'worker', 'php artisan queue:work', 1, false);
        $this->assertSame(0, AuditEvent::count());

        app(UpdateProcess::class)->handle($process, $this->user, 'horizon', 'php artisan horizon', 1, true);

        $this->assertSame(['horizon', 'php artisan horizon', 1, true], array_values($process->fresh()->only(['name', 'command', 'instances', 'exclusive'])));
        $this->assertSame([
            'environment_id' => $this->environment->id,
            'changes' => [
                'name' => ['from' => 'worker', 'to' => 'horizon'],
                'command' => ['from' => 'php artisan queue:work', 'to' => 'php artisan horizon'],
                'exclusive' => ['from' => false, 'to' => true],
            ],
        ], AuditEvent::sole()->context);
    }

    public function test_update_process_keeps_its_own_name_but_refuses_the_name_of_another(): void
    {
        $worker = $this->environment->processes()->create(['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false]);
        $this->environment->processes()->create(['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true]);

        app(UpdateProcess::class)->handle($worker, $this->user, 'worker', 'php artisan queue:work --queue=high', 3, false);
        $this->assertSame(['php artisan queue:work --queue=high', 3], [$worker->fresh()->command, $worker->fresh()->instances]);

        try {
            app(UpdateProcess::class)->handle($worker, $this->user, 'scheduler', 'php artisan queue:work', 1, false);
            $this->fail('UpdateProcess took the name of another process.');
        } catch (ValidationException $e) {
            $this->assertSame(['name' => ['This environment already has a process with the name scheduler.']], $e->errors());
        }

        $this->assertSame('worker', $worker->fresh()->name);
    }

    public function test_remove_process_deletes_it_and_records_its_name_on_the_environment(): void
    {
        $process = $this->environment->processes()->create(['name' => 'reverb', 'command' => 'php artisan reverb:start', 'instances' => 1, 'exclusive' => false]);

        app(RemoveProcess::class)->handle($process, $this->user);

        $this->assertSame(0, Process::count());
        $event = AuditEvent::sole();
        $this->assertSame(['process.removed', $this->environment->id, ['name' => 'reverb']], [$event->action, $event->subject_id, $event->context]);
    }
}
