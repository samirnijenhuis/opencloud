<?php

namespace Tests\Database\Migrations;

use App\Domains\Applications\Models\Application;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RunTheLaravelSchedulerAsALongRunningProcessTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    private Environment $production;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_28_200001_run_the_laravel_scheduler_as_a_long_running_process.php');
        $this->production = Environment::factory()->for(Application::factory())->create(['name' => 'production', 'cpu_target' => 60]);
    }

    public function test_up_turns_only_the_preset_scheduler_into_a_long_running_process(): void
    {
        $this->insert('scheduler', 'scheduled', 'php artisan schedule:run', '* * * * *', null, null, null);
        $this->insert('cleanup', 'scheduled', 'php artisan app:cleanup', '0 3 * * *', null, null, null);
        $this->insert('every-five', 'scheduled', 'php artisan schedule:run', '*/5 * * * *', null, null, null);
        $this->insert('worker', 'long_running', 'php artisan queue:work', null, 3, 5, 80);

        $this->migration->up();

        $this->assertSame([
            ['scheduler', 'long_running', 'php artisan schedule:work', null, 1, 1, 60],
            ['cleanup', 'scheduled', 'php artisan app:cleanup', '0 3 * * *', null, null, null],
            ['every-five', 'scheduled', 'php artisan schedule:run', '*/5 * * * *', null, null, null],
            ['worker', 'long_running', 'php artisan queue:work', null, 3, 5, 80],
        ], $this->rows());
    }

    public function test_down_turns_only_the_one_instance_scheduler_back_into_a_scheduled_process(): void
    {
        $this->insert('scheduler', 'long_running', 'php artisan schedule:work', null, 1, 1, 60);
        $this->insert('custom-scheduler', 'long_running', 'php artisan schedule:work', null, 2, 2, 60);
        $this->insert('worker', 'long_running', 'php artisan queue:work', null, 1, 1, 70);

        $this->migration->down();

        $this->assertSame([
            ['scheduler', 'scheduled', 'php artisan schedule:run', '* * * * *', null, null, null],
            ['custom-scheduler', 'long_running', 'php artisan schedule:work', null, 2, 2, 60],
            ['worker', 'long_running', 'php artisan queue:work', null, 1, 1, 70],
        ], $this->rows());
    }

    private function insert(string $name, string $kind, string $command, ?string $schedule, ?int $min, ?int $max, ?int $cpuTarget): void
    {
        DB::table('processes')->insert([
            'environment_id' => $this->production->id,
            'name' => $name,
            'kind' => $kind,
            'command' => $command,
            'schedule' => $schedule,
            'size' => 's',
            'min_instances' => $min,
            'max_instances' => $max,
            'cpu_target' => $cpuTarget,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array{string, string, string, ?string, ?int, ?int, ?int}> */
    private function rows(): array
    {
        return DB::table('processes')->orderBy('id')->get()->map(fn (object $row) => [
            $row->name, $row->kind, $row->command, $row->schedule,
            $row->min_instances === null ? null : (int) $row->min_instances,
            $row->max_instances === null ? null : (int) $row->max_instances,
            $row->cpu_target === null ? null : (int) $row->cpu_target,
        ])->all();
    }
}
