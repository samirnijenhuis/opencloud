<?php

namespace Tests\Database\Migrations;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddPreviewAttachmentsToEnvironmentsTableTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_03_000003_add_preview_attachments_to_environments_table.php');
        $this->cluster = Cluster::factory()->create();
    }

    public function test_each_template_gets_the_resources_of_its_production_and_every_other_environment_an_empty_list(): void
    {
        $shop = Application::factory()->for($this->cluster)->create();
        $production = Environment::factory()->for($shop)->create(['name' => 'production']);
        $template = Environment::factory()->for($shop)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        $preview = Environment::factory()->for($shop)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12]);
        $this->attach($production, DatastoreKind::MYSQL, ['name' => 'main', 'is_default' => false]);
        $this->attach($production, DatastoreKind::VALKEY);
        $this->attach($production, DatastoreKind::POSTGRES, ['name' => 'reports', 'is_default' => true], DatastoreSource::MANAGED);
        $this->attach($production, DatastoreKind::MYSQL, ['name' => 'legacy', 'is_default' => false], DatastoreSource::EXTERNAL);
        // A managed database that DigitalOcean destroys has no name, and a preview never got it.
        $this->attach($production, DatastoreKind::POSTGRES, [], DatastoreSource::MANAGED, DatastoreStatus::DESTROYING);
        $this->attach($production, DatastoreKind::RUSTFS, ['name' => 'main', 'is_default' => true]);
        $this->attach($preview, DatastoreKind::MYSQL, ['name' => 'main', 'is_default' => true]);
        $this->attach($template, DatastoreKind::RUSTFS, ['name' => 'uploads', 'is_default' => true]);
        // An application without production, and one whose production has no resources.
        $blog = Environment::factory()->for(Application::factory()->for($this->cluster))->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        $docsApplication = Application::factory()->for($this->cluster)->create();
        $docsProduction = Environment::factory()->for($docsApplication)->create(['name' => 'production']);
        $docs = Environment::factory()->for($docsApplication)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        $this->migration->down();

        $this->migration->up();

        $this->assertSame([
            $production->id => [],
            $template->id => [
                ['kind' => 'mysql', 'name' => 'main', 'is_default' => false],
                ['kind' => 'valkey', 'name' => null, 'is_default' => false],
                ['kind' => 'postgres', 'name' => 'reports', 'is_default' => true],
                ['kind' => 'mysql', 'name' => 'legacy', 'is_default' => false],
                ['kind' => 'rustfs', 'name' => 'main', 'is_default' => true],
            ],
            $preview->id => [],
            $blog->id => [],
            $docsProduction->id => [],
            $docs->id => [],
        ], DB::table('environments')->orderBy('id')->pluck('preview_attachments', 'id')->map(fn (string $list) => json_decode($list, true))->all());
    }

    public function test_down_takes_the_list_away(): void
    {
        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('environments', 'preview_attachments'));
    }

    /**
     * A cluster has one standard server of each kind, which its environments share.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function attach(Environment $environment, DatastoreKind $kind, array $attributes = [], DatastoreSource $source = DatastoreSource::STANDARD, DatastoreStatus $status = DatastoreStatus::READY): DatastoreAttachment
    {
        $datastore = $source === DatastoreSource::STANDARD
            ? Datastore::query()->firstOrCreate(['cluster_id' => $this->cluster->id, 'kind' => $kind, 'source' => $source], ['status' => $status, 'version' => '1'])
            : Datastore::factory()->for($this->cluster)->create(['kind' => $kind, 'source' => $source, 'status' => $status]);

        return DatastoreAttachment::factory()->create([
            'datastore_id' => $datastore->id,
            'environment_id' => $environment->id,
            ...$attributes,
        ]);
    }
}
