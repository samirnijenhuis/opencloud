<?php

namespace Tests\Database\Migrations;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Env\EnvValue;
use App\Domains\Datastores\Env\LaravelProfile;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\SqlConnection;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddConnectionNamesToDatastoreAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_25_000001_add_connection_names_to_datastore_attachments.php');
        $this->migration->down();
        $this->cluster = Cluster::factory()->create();
    }

    public function test_each_mysql_or_postgres_attachment_becomes_the_default_main_with_the_host_and_port_of_its_env_values(): void
    {
        $shop = Environment::factory()->create();
        $blog = Environment::factory()->create();
        $pending = Environment::factory()->create();
        $mysql = $this->attachment($shop, DatastoreKind::MYSQL, DatastoreSource::STANDARD, [
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'mysql-1.oc-datastores.svc.cluster.local', 'DB_PORT' => '3306',
            'DB_DATABASE' => "env_{$shop->id}", 'DB_USERNAME' => "env_{$shop->id}", 'DB_PASSWORD' => 'shop-password',
        ]);
        $valkey = $this->attachment($shop, DatastoreKind::VALKEY, DatastoreSource::STANDARD, ['REDIS_HOST' => 'valkey-2.oc-datastores.svc.cluster.local']);
        $managed = $this->attachment($blog, DatastoreKind::POSTGRES, DatastoreSource::MANAGED, [
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'private-db.ondigitalocean.com', 'DB_PORT' => '25060',
            'DB_DATABASE' => "env_{$blog->id}", 'DB_USERNAME' => "env_{$blog->id}", 'DB_PASSWORD' => 'blog-password',
        ]);
        $unapplied = $this->attachment($pending, DatastoreKind::POSTGRES, DatastoreSource::MANAGED, []);
        $before = [$this->envOf($mysql), $this->envOf($managed)];

        $this->migration->up();

        $this->assertSame([
            [$mysql->id, 'main', 1, 'mysql-1.oc-datastores.svc.cluster.local', 3306],
            [$valkey->id, null, 0, null, null],
            [$managed->id, 'main', 1, 'private-db.ondigitalocean.com', 25060],
            [$unapplied->id, 'main', 1, null, null],
        ], DB::table('datastore_attachments')->orderBy('id')->get()
            ->map(fn (object $row) => [$row->id, $row->name, (int) $row->is_default, $row->host, $row->port === null ? null : (int) $row->port])
            ->all());
        $this->assertSame($before, [$this->laravelEnvOf($mysql), $this->laravelEnvOf($managed)]);
    }

    /** @param  array<string, string>  $env */
    private function attachment(Environment $environment, DatastoreKind $kind, DatastoreSource $source, array $env): DatastoreAttachment
    {
        $attachment = DatastoreAttachment::factory()->create([
            'datastore_id' => Datastore::factory()->for($this->cluster)->create(['kind' => $kind, 'source' => $source])->id,
            'environment_id' => $environment->id,
            'database_name' => "env_{$environment->id}",
            'username' => "env_{$environment->id}",
            'password' => $env['DB_PASSWORD'] ?? 'valkey-password',
        ]);

        foreach ($env as $key => $value) {
            EnvVar::factory()->for($environment)->create([
                'key' => $key,
                'value' => $value,
                'is_secret' => $key === 'DB_PASSWORD',
                'source' => EnvVarSource::DATASTORE,
                'datastore_attachment_id' => $attachment->id,
            ]);
        }

        return $attachment;
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function envOf(DatastoreAttachment $attachment): array
    {
        return $attachment->envVars()->orderBy('id')->get()->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret]])->all();
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function laravelEnvOf(DatastoreAttachment $attachment): array
    {
        return array_map(
            fn (EnvValue $value) => [$value->value, $value->secret],
            SqlConnection::of($attachment->fresh())->envFor(new LaravelProfile),
        );
    }
}
