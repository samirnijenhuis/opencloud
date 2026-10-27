<?php

namespace Tests\Database\Migrations;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreAuditSubjectsByMorphAliasTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_27_000001_store_audit_subjects_by_morph_alias.php');
    }

    public function test_up_replaces_each_old_model_class_with_its_alias_and_the_subject_loads(): void
    {
        $environment = Environment::factory()->create();
        $this->event('App\\Models\\Environment', $environment->id);
        $this->event('App\\Models\\DashboardDomain', 7);
        $this->event(null, null);

        $this->migration->up();

        $this->assertSame(['environment', 'dashboard_domain', null], DB::table('audit_events')->orderBy('id')->pluck('subject_type')->all());
        $this->assertTrue(AuditEvent::query()->orderBy('id')->firstOrFail()->subject->is($environment));
    }

    public function test_down_puts_the_old_model_class_back(): void
    {
        $this->event('environment', 1);
        $this->event('dashboard_domain', 7);

        $this->migration->down();

        $this->assertSame(['App\\Models\\Environment', 'App\\Models\\DashboardDomain'], DB::table('audit_events')->orderBy('id')->pluck('subject_type')->all());
    }

    private function event(?string $type, ?int $id): void
    {
        DB::table('audit_events')->insert(['action' => 'test.done', 'subject_type' => $type, 'subject_id' => $id, 'context' => '[]', 'created_at' => now()]);
    }
}
