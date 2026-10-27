<?php

namespace Tests\Database\Migrations;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Env\EnvValue;
use App\Domains\Datastores\Env\LaravelProfile;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddBucketNamesToDatastoreAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_27_300001_add_bucket_names_to_datastore_attachments.php');
        $this->migration->down();
        $this->cluster = Cluster::factory()->create();
    }

    public function test_each_bucket_becomes_the_default_main_with_the_same_env_and_each_row_gets_its_family(): void
    {
        $shop = Environment::factory()->for(Application::factory()->for($this->cluster))->create();
        $blog = Environment::factory()->create();
        $mysql = $this->attachment($shop, $this->datastore(DatastoreKind::MYSQL), ['name' => 'main', 'is_default' => true]);
        $valkey = $this->attachment($shop, $this->datastore(DatastoreKind::VALKEY));
        $bucket = $this->attachment($shop, $rustfs = $this->datastore(DatastoreKind::RUSTFS), ['database_name' => "env-{$shop->id}", 'username' => "env-{$shop->id}", 'password' => 'bucket-secret']);
        $env = [
            'FILESYSTEM_DISK' => ['s3', false],
            'AWS_ACCESS_KEY_ID' => ["env-{$shop->id}", false],
            'AWS_SECRET_ACCESS_KEY' => ['bucket-secret', true],
            'AWS_DEFAULT_REGION' => ['us-east-1', false],
            'AWS_BUCKET' => ["env-{$shop->id}", false],
            'AWS_ENDPOINT' => ["http://rustfs-{$rustfs->id}.oc-datastores.svc.cluster.local:9000", false],
            'AWS_USE_PATH_STYLE_ENDPOINT' => ['true', false],
        ];
        foreach ($env as $key => [$value, $secret]) {
            EnvVar::factory()->for($shop)->create(['key' => $key, 'value' => $value, 'is_secret' => $secret, 'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $bucket->id]);
        }
        // A Spaces bucket that OpenTofu destroys, and the bucket that the environment got after it.
        $destroying = $this->attachment($blog, $this->datastore(DatastoreKind::RUSTFS, DatastoreSource::MANAGED, DatastoreStatus::DESTROYING));
        $spaces = $this->attachment($blog, $this->datastore(DatastoreKind::RUSTFS, DatastoreSource::MANAGED));

        $this->migration->up();

        $this->assertSame([
            [$mysql->id, 'database', 'main', 1],
            [$valkey->id, 'key_value', null, 0],
            [$bucket->id, 'bucket', 'main', 1],
            [$destroying->id, 'bucket', null, 0],
            [$spaces->id, 'bucket', 'main', 1],
        ], DB::table('datastore_attachments')->orderBy('id')->get()
            ->map(fn (object $row) => [$row->id, $row->family, $row->name, (int) $row->is_default])
            ->all());
        $this->assertSame($env, array_map(
            fn (EnvValue $value) => [$value->value, $value->secret],
            Provisioners::of($rustfs)->connection($bucket->fresh())->envFor(new LaravelProfile),
        ));

        try {
            $this->attachment($shop, $rustfs, ['family' => 'bucket', 'name' => 'uploads', 'is_default' => true]);
            $this->fail('A second default bucket was stored.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('datastore_attachments.environment_id, datastore_attachments.family', $e->getMessage());
        }
    }

    public function test_down_takes_the_names_of_the_buckets_and_the_family_column_away(): void
    {
        $environment = Environment::factory()->create();
        $mysql = $this->attachment($environment, $this->datastore(DatastoreKind::MYSQL), ['name' => 'main', 'is_default' => true]);
        $bucket = $this->attachment($environment, $this->datastore(DatastoreKind::RUSTFS));
        $this->migration->up();

        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('datastore_attachments', 'family'));
        $this->assertSame(
            [[$mysql->id, 'main', 1], [$bucket->id, null, 0]],
            DB::table('datastore_attachments')->orderBy('id')->get()->map(fn (object $row) => [$row->id, $row->name, (int) $row->is_default])->all(),
        );
    }

    private function datastore(DatastoreKind $kind, DatastoreSource $source = DatastoreSource::STANDARD, DatastoreStatus $status = DatastoreStatus::READY): Datastore
    {
        return Datastore::factory()->for($this->cluster)->create(['kind' => $kind, 'source' => $source, 'status' => $status]);
    }

    /**
     * The factory fills the family, which the rows from before this migration do not have.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function attachment(Environment $environment, Datastore $datastore, array $attributes = []): DatastoreAttachment
    {
        return DatastoreAttachment::query()->create([
            'datastore_id' => $datastore->id,
            'environment_id' => $environment->id,
            'database_name' => "env-{$environment->id}",
            'username' => "env-{$environment->id}",
            'password' => 'attachment-password',
            ...$attributes,
        ]);
    }
}
