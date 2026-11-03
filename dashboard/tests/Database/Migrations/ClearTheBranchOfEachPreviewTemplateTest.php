<?php

namespace Tests\Database\Migrations;

use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClearTheBranchOfEachPreviewTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_03_000002_clear_the_branch_of_each_preview_template.php');
    }

    public function test_up_clears_the_branch_and_deploy_on_push_of_each_preview_template_only(): void
    {
        $production = Environment::factory()->create(['kind' => EnvironmentKind::PERMANENT, 'branch' => 'main', 'auto_deploy' => true]);
        $template = Environment::factory()->create(['kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => 'main', 'auto_deploy' => true]);
        $preview = Environment::factory()->create(['kind' => EnvironmentKind::PREVIEW, 'branch' => 'feature/login', 'auto_deploy' => true]);

        $this->migration->up();

        $this->assertSame(
            [$production->id => ['main', 1], $template->id => [null, 0], $preview->id => ['feature/login', 1]],
            DB::table('environments')->orderBy('id')->get()->mapWithKeys(fn (object $row) => [$row->id => [$row->branch, (int) $row->auto_deploy]])->all(),
        );
    }
}
