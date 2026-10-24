<?php

namespace Tests\Feature\Migrations;

use App\Enums\ApplicationType;
use App\Enums\EnvironmentKind;
use App\Models\Application;
use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MoveWorkerAndSchedulerIntoProcessesTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER = ['worker', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 1, 0];

    private const SCHEDULER = ['scheduler', 'php artisan schedule:work', 1, 1];

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_24_100002_move_worker_and_scheduler_into_processes.php');
        $this->migration->down();
    }

    public function test_each_switch_that_is_on_becomes_a_row_for_a_laravel_app_and_the_columns_go(): void
    {
        $shop = Application::factory()->create(['type' => ApplicationType::Laravel]);
        $site = Application::factory()->create(['type' => ApplicationType::Dockerfile]);
        $workerOnly = $this->environment($shop, 'production', worker: true, scheduler: false);
        $template = $this->environment($shop, 'preview-template', worker: true, scheduler: true, kind: EnvironmentKind::PreviewTemplate);
        $this->environment($shop, 'staging', worker: false, scheduler: false);
        $this->environment($site, 'production', worker: true, scheduler: true);

        $this->migration->up();

        $this->assertSame([
            [$workerOnly->id, ...self::WORKER],
            [$template->id, ...self::WORKER],
            [$template->id, ...self::SCHEDULER],
        ], $this->rows());
        $this->assertSame([false, false], [Schema::hasColumn('environments', 'worker_enabled'), Schema::hasColumn('environments', 'scheduler_enabled')]);
    }

    public function test_down_turns_the_worker_and_scheduler_rows_back_into_switches(): void
    {
        $shop = Application::factory()->create(['type' => ApplicationType::Laravel]);
        $production = $this->environment($shop, 'production', worker: true, scheduler: false);
        $this->migration->up();
        DB::table('processes')->insert(['environment_id' => $production->id, 'name' => 'horizon', 'command' => 'php artisan horizon', 'instances' => 1, 'exclusive' => false]);

        $this->migration->down();

        $this->assertSame(
            ['worker_enabled' => 1, 'scheduler_enabled' => 0],
            (array) DB::table('environments')->where('id', $production->id)->first(['worker_enabled', 'scheduler_enabled']),
        );
        $this->assertSame([[$production->id, 'horizon', 'php artisan horizon', 1, 0]], $this->rows());
    }

    private function environment(Application $application, string $name, bool $worker, bool $scheduler, EnvironmentKind $kind = EnvironmentKind::Permanent): Environment
    {
        $environment = Environment::factory()->for($application)->create(['name' => $name, 'kind' => $kind]);
        DB::table('environments')->where('id', $environment->id)->update(['worker_enabled' => $worker, 'scheduler_enabled' => $scheduler]);

        return $environment;
    }

    /** @return list<array{0: int, 1: string, 2: string, 3: int, 4: int}> */
    private function rows(): array
    {
        return DB::table('processes')->orderBy('id')->get()
            ->map(fn (object $row) => [(int) $row->environment_id, $row->name, $row->command, (int) $row->instances, (int) $row->exclusive])
            ->all();
    }
}
