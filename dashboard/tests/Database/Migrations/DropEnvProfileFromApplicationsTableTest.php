<?php

namespace Tests\Database\Migrations;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropEnvProfileFromApplicationsTableTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_28_100001_drop_env_profile_from_applications_table.php');
        $this->cluster = Cluster::factory()->create();
    }

    public function test_up_drops_the_env_profile_and_keeps_each_application(): void
    {
        $this->migration->down();
        $this->application('shop', ApplicationType::LARAVEL, 'generic');
        $this->application('php', ApplicationType::DOCKERFILE, 'laravel');
        $this->application('api', ApplicationType::NODE, 'generic');

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('applications', 'env_profile'));
        $this->assertSame(
            [['shop', 'laravel'], ['php', 'dockerfile'], ['api', 'node']],
            DB::table('applications')->orderBy('id')->get()->map(fn (object $row) => [$row->name, $row->type])->all(),
        );
    }

    public function test_down_gives_each_application_the_env_profile_of_its_preset(): void
    {
        foreach (ApplicationType::cases() as $type) {
            $this->application($type->value, $type);
        }

        $this->migration->down();

        $this->assertSame(
            ['laravel' => 'laravel', 'node' => 'generic', 'python' => 'generic', 'ruby' => 'generic', 'static' => 'generic', 'dockerfile' => 'generic'],
            DB::table('applications')->orderBy('id')->pluck('env_profile', 'name')->all(),
        );
    }

    private function application(string $name, ApplicationType $type, ?string $envProfile = null): void
    {
        $application = Application::factory()->for($this->cluster)->create(['name' => $name, 'type' => $type]);

        if ($envProfile !== null) {
            DB::table('applications')->where('id', $application->id)->update(['env_profile' => $envProfile]);
        }
    }
}
