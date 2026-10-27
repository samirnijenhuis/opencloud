<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Actions\AddProcess;
use App\Domains\Environments\Actions\RemoveProcess;
use App\Domains\Environments\Actions\UpdateProcess;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessActionsTest extends TestCase
{
    use RefreshDatabase;

    private const LONG = ProcessKind::LONG_RUNNING;

    private const SCHEDULED = ProcessKind::SCHEDULED;

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
        $process = app(AddProcess::class)->execute($this->environment, $this->user, ' horizon ', '  php artisan horizon ', self::LONG, null, Size::M, 2, 4, 60);

        $this->assertSame(
            [$this->environment->id, 'horizon', self::LONG, 'php artisan horizon', null, Size::M, 2, 4, 60],
            [$process->environment_id, $process->name, $process->kind, $process->command, $process->schedule, $process->size, $process->min_instances, $process->max_instances, $process->cpu_target],
        );
        $event = AuditEvent::sole();
        $this->assertSame(['process.added', $this->user->id, $process->id], [$event->action, $event->user_id, $event->subject_id]);
        $this->assertSame([
            'environment_id' => $this->environment->id,
            'name' => 'horizon',
            'kind' => 'long_running',
            'command' => 'php artisan horizon',
            'size' => 'm',
            'min_instances' => 2,
            'max_instances' => 4,
            'cpu_target' => 60,
            'schedule' => null,
        ], $event->context);
    }

    public function test_a_scheduled_process_keeps_its_schedule_and_drops_the_instance_values(): void
    {
        $process = app(AddProcess::class)->execute($this->environment, $this->user, 'scheduler', 'php artisan schedule:run', self::SCHEDULED, ' */5 * * * * ', Size::XS, 3, 9, 50);

        $this->assertSame(
            [self::SCHEDULED, '*/5 * * * *', Size::XS, null, null, null],
            [$process->kind, $process->schedule, $process->size, $process->min_instances, $process->max_instances, $process->cpu_target],
        );
    }

    public function test_a_long_running_process_drops_a_schedule(): void
    {
        $process = app(AddProcess::class)->execute($this->environment, $this->user, 'worker', 'php artisan queue:work', self::LONG, '* * * * *', Size::S, 1, 1, 70);

        $this->assertNull($process->fresh()->schedule);
    }

    /**
     * @param  array{0: string, 1: string, 2: ProcessKind, 3: ?string, 4: ?int, 5: ?int, 6: ?int}  $arguments
     * @param  array<string, list<string>>  $errors
     */
    #[DataProvider('refusals')]
    public function test_add_process_explains_each_value_it_refuses(array $arguments, array $errors): void
    {
        app(AddProcess::class)->execute($this->environment, $this->user, 'worker', 'php artisan queue:work', self::LONG, null, Size::S, 1, 1, 70);
        [$name, $command, $kind, $schedule, $min, $max, $cpu] = $arguments;

        try {
            app(AddProcess::class)->execute($this->environment, $this->user, $name, $command, $kind, $schedule, Size::S, $min, $max, $cpu);
            $this->fail('AddProcess accepted '.json_encode($arguments).'.');
        } catch (ValidationException $e) {
            $this->assertSame($errors, $e->errors());
        }

        $this->assertSame(['worker'], $this->environment->processes()->pluck('name')->all());
    }

    /** @return array<string, array{0: array<int, mixed>, 1: array<string, list<string>>}> */
    public static function refusals(): array
    {
        $name = ['name' => ['Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.']];
        $reserved = ['name' => ['Opencloud uses the names web, deploy, and command. Use a different name.']];
        $cron = ['schedule' => ['Enter a cron schedule with five fields, for example */5 * * * * for each five minutes. Kubernetes does not accept L, W, or #.']];

        return [
            'uppercase name' => [['Worker', 'php artisan queue:work', self::LONG, null, 1, 1, 70], $name],
            'leading dash' => [['-ssr', 'php artisan inertia:start-ssr', self::LONG, null, 1, 1, 70], $name],
            'long name' => [[str_repeat('a', 31), 'php artisan horizon', self::LONG, null, 1, 1, 70], $name],
            'web' => [['web', 'php artisan serve', self::LONG, null, 1, 1, 70], $reserved],
            'deploy' => [['deploy', 'php artisan migrate', self::LONG, null, 1, 1, 70], $reserved],
            'command' => [['command', 'php artisan tinker', self::LONG, null, 1, 1, 70], $reserved],
            'taken name' => [['worker', 'php artisan queue:work', self::LONG, null, 1, 1, 70], ['name' => ['This environment already has a process with the name worker.']]],
            'blank command' => [['reverb', '   ', self::LONG, null, 1, 1, 70], ['command' => ['Enter the command that starts the process.']]],
            'no instances' => [['reverb', 'php artisan reverb:start', self::LONG, null, 0, 1, 70], ['min_instances' => ['Use 1 to 20 instances.']]],
            'too many instances' => [['reverb', 'php artisan reverb:start', self::LONG, null, 1, 21, 70], ['max_instances' => ['Use 1 to 20 instances.']]],
            'maximum below minimum' => [['reverb', 'php artisan reverb:start', self::LONG, null, 3, 2, 70], ['max_instances' => ['Use a maximum that is the same as the minimum or more.']]],
            'cpu target' => [['reverb', 'php artisan reverb:start', self::LONG, null, 1, 3, 99], ['cpu_target' => ['Use a CPU target from 10 to 95 percent.']]],
            'long-running without instances' => [['reverb', 'php artisan reverb:start', self::LONG, null, null, null, null], [
                'min_instances' => ['Use 1 to 20 instances.'],
                'max_instances' => ['Use 1 to 20 instances.'],
                'cpu_target' => ['Use a CPU target from 10 to 95 percent.'],
            ]],
            'scheduled without schedule' => [['cron', 'php artisan schedule:run', self::SCHEDULED, null, null, null, null], ['schedule' => ['Enter a schedule, for example * * * * * for each minute.']]],
            'six fields' => [['cron', 'php artisan schedule:run', self::SCHEDULED, '* * * * * *', null, null, null], $cron],
            'last day of the month' => [['cron', 'php artisan schedule:run', self::SCHEDULED, '0 0 L * *', null, null, null], $cron],
            'nth weekday' => [['cron', 'php artisan schedule:run', self::SCHEDULED, '0 9 * * 1#2', null, null, null], $cron],
        ];
    }

    public function test_a_schedule_with_names_and_a_shortcut_is_valid(): void
    {
        $add = fn (string $name, string $schedule) => app(AddProcess::class)->execute($this->environment, $this->user, $name, 'php artisan report', self::SCHEDULED, $schedule, Size::S, null, null, null);

        $add('july', '0 6 * JUL WED');
        $add('daily', '@daily');

        $this->assertSame(['0 6 * JUL WED', '@daily'], $this->environment->processes()->pluck('schedule')->all());
    }

    public function test_a_process_with_the_same_name_in_another_environment_is_allowed(): void
    {
        $other = Environment::factory()->for($this->environment->application)->create(['name' => 'staging']);
        app(AddProcess::class)->execute($other, $this->user, 'worker', 'php artisan queue:work', self::LONG, null, Size::S, 1, 1, 70);

        $process = app(AddProcess::class)->execute($this->environment, $this->user, 'worker', 'php artisan queue:work', self::LONG, null, Size::S, 1, 1, 70);

        $this->assertSame([$this->environment->id, 'worker'], [$process->environment_id, $process->name]);
    }

    public function test_update_process_records_only_the_changes(): void
    {
        $process = Process::factory()->for($this->environment)->create(['name' => 'worker', 'command' => 'php artisan queue:work']);

        app(UpdateProcess::class)->execute($process, $this->user, 'worker', 'php artisan queue:work', self::LONG, null, Size::S, 1, 1, 70);
        $this->assertSame(0, AuditEvent::count());

        app(UpdateProcess::class)->execute($process, $this->user, 'worker', 'php artisan queue:work', self::LONG, null, Size::L, 2, 6, 70);

        $this->assertSame([
            'environment_id' => $this->environment->id,
            'changes' => [
                'size' => ['from' => 's', 'to' => 'l'],
                'min_instances' => ['from' => 1, 'to' => 2],
                'max_instances' => ['from' => 1, 'to' => 6],
            ],
        ], AuditEvent::sole()->context);
    }

    public function test_a_long_running_process_that_becomes_scheduled_loses_its_instances(): void
    {
        $process = Process::factory()->for($this->environment)->create(['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'min_instances' => 1, 'max_instances' => 1]);

        app(UpdateProcess::class)->execute($process, $this->user, 'scheduler', 'php artisan schedule:run', self::SCHEDULED, '* * * * *', Size::S, 1, 1, 70);

        $this->assertSame(
            [self::SCHEDULED, 'php artisan schedule:run', '* * * * *', null, null, null],
            array_values($process->fresh()->only(['kind', 'command', 'schedule', 'min_instances', 'max_instances', 'cpu_target'])),
        );
        $this->assertSame([
            'kind' => ['from' => 'long_running', 'to' => 'scheduled'],
            'command' => ['from' => 'php artisan schedule:work', 'to' => 'php artisan schedule:run'],
            'schedule' => ['from' => null, 'to' => '* * * * *'],
            'min_instances' => ['from' => 1, 'to' => null],
            'max_instances' => ['from' => 1, 'to' => null],
            'cpu_target' => ['from' => 70, 'to' => null],
        ], AuditEvent::sole()->context['changes']);
    }

    public function test_update_process_keeps_its_own_name_but_refuses_the_name_of_another(): void
    {
        $worker = Process::factory()->for($this->environment)->create(['name' => 'worker', 'command' => 'php artisan queue:work']);
        Process::factory()->for($this->environment)->scheduled()->create();

        app(UpdateProcess::class)->execute($worker, $this->user, 'worker', 'php artisan queue:work --queue=high', self::LONG, null, Size::S, 3, 3, 70);
        $this->assertSame(['php artisan queue:work --queue=high', 3], [$worker->fresh()->command, $worker->fresh()->min_instances]);

        try {
            app(UpdateProcess::class)->execute($worker, $this->user, 'scheduler', 'php artisan queue:work', self::LONG, null, Size::S, 1, 1, 70);
            $this->fail('UpdateProcess took the name of another process.');
        } catch (ValidationException $e) {
            $this->assertSame(['name' => ['This environment already has a process with the name scheduler.']], $e->errors());
        }

        $this->assertSame('worker', $worker->fresh()->name);
    }

    public function test_remove_process_deletes_it_and_records_its_name_on_the_environment(): void
    {
        $process = Process::factory()->for($this->environment)->create(['name' => 'reverb', 'command' => 'php artisan reverb:start']);

        app(RemoveProcess::class)->execute($process, $this->user);

        $this->assertSame(0, Process::count());
        $event = AuditEvent::sole();
        $this->assertSame(['process.removed', $this->environment->id, ['name' => 'reverb']], [$event->action, $event->subject_id, $event->context]);
    }
}
