<?php

namespace Tests\Database\Migrations;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MakePreviewDomainAPatternTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_31_000001_make_preview_domain_a_pattern.php');
    }

    private function application(string $name, ?string $domain): void
    {
        $application = Application::factory()->for(Cluster::factory()->create())->create(['name' => $name]);
        DB::table('applications')->where('id', $application->id)->update(['preview_domain' => $domain]);
    }

    /** @return array<string, ?string> */
    private function domains(): array
    {
        return DB::table('applications')->orderBy('id')->pluck('preview_domain', 'name')->all();
    }

    public function test_up_puts_the_old_first_part_before_each_base_domain(): void
    {
        $this->application('shop', 'preview.example.com');
        $this->application('api', null);

        $this->migration->up();

        $this->assertSame(['shop' => 'pr-{pr_number}.preview.example.com', 'api' => null], $this->domains());
    }

    public function test_down_strips_the_first_part_and_drops_a_pattern_that_does_not_start_with_it(): void
    {
        $this->application('shop', 'pr-{pr_number}.preview.example.com');
        $this->application('api', 'review-{pr_number}.example.com');
        $this->application('web', null);

        $this->migration->down();

        $this->assertSame(['shop' => 'preview.example.com', 'api' => null, 'web' => null], $this->domains());
    }
}
