<?php

namespace Tests\Database\Migrations;

use App\Domains\Applications\Models\Application;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddKindAndScalingToProcessesTableTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_27_200001_add_kind_and_scaling_to_processes_table.php');
        $this->migration->down();
    }

    public function test_the_exclusive_scheduler_becomes_scheduled_and_every_other_row_runs_long_with_the_size_of_its_environment(): void
    {
        $application = Application::factory()->create();
        $production = Environment::factory()->for($application)->create(['name' => 'production', 'size' => Size::M, 'cpu_target' => 60]);
        $staging = Environment::factory()->for($application)->create(['name' => 'staging', 'size' => Size::XS, 'cpu_target' => 80]);
        $this->insert($production, 'worker', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 3, false);
        $this->insert($production, 'scheduler', 'php artisan schedule:work', 1, true);
        $this->insert($production, 'horizon', 'php artisan horizon', 1, true);
        $this->insert($staging, 'cron', 'cd /app && php artisan schedule:work', 1, true);
        $this->insert($staging, 'reverb', 'php artisan reverb:start', 2, false);

        $this->migration->up();

        $this->assertSame([
            [$production->id, 'worker', 'long_running', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', null, 'm', 3, 3, 60],
            [$production->id, 'scheduler', 'scheduled', 'php artisan schedule:run', '* * * * *', 'm', null, null, null],
            [$production->id, 'horizon', 'long_running', 'php artisan horizon', null, 'm', 1, 1, 60],
            [$staging->id, 'cron', 'scheduled', 'cd /app && php artisan schedule:run', '* * * * *', 'xs', null, null, null],
            [$staging->id, 'reverb', 'long_running', 'php artisan reverb:start', null, 'xs', 2, 2, 80],
        ], DB::table('processes')->orderBy('id')->get()->map(fn (object $row) => [
            (int) $row->environment_id, $row->name, $row->kind, $row->command, $row->schedule, $row->size,
            $row->min_instances === null ? null : (int) $row->min_instances,
            $row->max_instances === null ? null : (int) $row->max_instances,
            $row->cpu_target === null ? null : (int) $row->cpu_target,
        ])->all());
        $this->assertSame([false, false], [Schema::hasColumn('processes', 'exclusive'), Schema::hasColumn('processes', 'instances')]);
        $this->assertSame(
            ['kind' => false, 'min_instances' => true, 'schedule' => true, 'size' => false],
            collect(Schema::getColumns('processes'))->whereIn('name', ['kind', 'size', 'schedule', 'min_instances'])->pluck('nullable', 'name')->sortKeys()->all(),
        );
        $this->assertSame(['primary', 'processes_environment_id_name_unique'], array_column(Schema::getIndexes('processes'), 'name'));
        $this->assertSame(
            [['columns' => ['environment_id'], 'foreign_table' => 'environments', 'on_delete' => 'cascade']],
            array_map(fn (array $key) => ['columns' => $key['columns'], 'foreign_table' => $key['foreign_table'], 'on_delete' => $key['on_delete']], Schema::getForeignKeys('processes')),
        );
    }

    public function test_down_turns_a_scheduled_process_back_into_the_exclusive_scheduler(): void
    {
        $production = Environment::factory()->for(Application::factory())->create();
        $this->insert($production, 'worker', 'php artisan queue:work', 2, false);
        $this->insert($production, 'scheduler', 'php artisan schedule:work', 1, true);
        $this->migration->up();

        $this->migration->down();

        $this->assertSame([
            [$production->id, 'worker', 'php artisan queue:work', 2, 0],
            [$production->id, 'scheduler', 'php artisan schedule:work', 1, 1],
        ], DB::table('processes')->orderBy('id')->get()
            ->map(fn (object $row) => [(int) $row->environment_id, $row->name, $row->command, (int) $row->instances, (int) $row->exclusive])
            ->all());
        $this->assertFalse(Schema::hasColumn('processes', 'kind'));
    }

    private function insert(Environment $environment, string $name, string $command, int $instances, bool $exclusive): void
    {
        DB::table('processes')->insert([
            'environment_id' => $environment->id,
            'name' => $name,
            'command' => $command,
            'instances' => $instances,
            'exclusive' => $exclusive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
