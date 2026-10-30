<?php

namespace Tests\Database\Migrations;

use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddSleepToEnvironmentsTableTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_29_100001_add_sleep_to_environments_table.php');
    }

    public function test_previews_and_preview_templates_sleep_after_15_minutes_and_permanent_environments_never_sleep(): void
    {
        $this->migration->down();
        $production = Environment::factory()->create(['kind' => EnvironmentKind::PERMANENT]);
        $template = Environment::factory()->create(['kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        $preview = Environment::factory()->create(['kind' => EnvironmentKind::PREVIEW]);

        $this->migration->up();

        $this->assertSame(
            [$production->id => null, $template->id => 15, $preview->id => 15],
            DB::table('environments')->orderBy('id')->pluck('sleep_after_minutes', 'id')->all(),
        );
    }

    public function test_down_removes_the_columns(): void
    {
        $this->migration->down();

        foreach (['sleep_after_minutes', 'last_request_at', 'slept_at', 'woke_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('environments', $column), $column);
        }
        $this->assertTrue(Schema::hasColumn('environments', 'name'));
    }
}
