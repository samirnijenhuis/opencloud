<?php

namespace Tests\Feature\Datastores;

use App\Datastores\AttachmentEnv;
use App\Datastores\BucketConnection;
use App\Datastores\KeyValueConnection;
use App\Datastores\Provisioners;
use App\Datastores\SqlConnection;
use App\Deploy\EnvironmentVariables;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the env values of every attachment shape before the env names move from the engines to the env profiles.
 *
 * The contract commit adds this file and the golden file, recorded from the code at 246631a. After that commit nobody
 * edits either file: a change to them is a change to the env of every deployed Laravel app. The scenario writes rows
 * with fixed ids and passwords and calls only AttachmentEnv::write() and EnvironmentVariables::runtime(), which keep
 * their signatures through both workstreams, so the same test runs before the move, in each worktree, and after both
 * merge.
 */
class EnvPinTest extends TestCase
{
    use RefreshDatabase;

    private const GOLDEN = 'tests/Fixtures/env/laravel.json';

    /** Workstream B's proof. The rows and the merged runtime env stay the same, byte for byte. */
    public function test_every_attachment_shape_writes_the_same_laravel_env(): void
    {
        $json = json_encode($this->scenario(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $golden = base_path(self::GOLDEN);

        if (! file_exists($golden)) {
            // Runs once, in the contract commit, on the code before the move. The commit holds the result.
            @mkdir(dirname($golden), 0755, true);
            file_put_contents($golden, $json);
            $this->markTestIncomplete('Recorded '.self::GOLDEN.' from the current code. Commit it and run the test again.');
        }

        $this->assertSame(file_get_contents($golden), $json);
    }

    /** Workstream C's proof. The registry gives each shape the connection that the engines give it at the contract commit. */
    public function test_every_attachment_shape_has_the_same_connection(): void
    {
        $this->scenario();

        $this->assertEquals([
            21 => new SqlConnection(DatastoreKind::Mysql, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_7', 'env_7', 'mysql-main-pass', 'main', true),
            22 => new SqlConnection(DatastoreKind::Postgres, 'postgres-4.oc-datastores.svc.cluster.local', 5432, 'env_7_reports', 'env_7_0b7ec688', 'p/ss+w@rd:1%', 'reports', false),
            23 => new SqlConnection(DatastoreKind::Mysql, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_7_analytics', 'env_7_66d9b558', 'analytics-pass', 'analytics', false),
            24 => new KeyValueConnection('valkey-24.oc-datastores.svc.cluster.local', 6379, 'valkey-pass', tls: false),
            25 => new BucketConnection('http://rustfs-6.oc-datastores.svc.cluster.local:9000', 'us-east-1', 'env-7', 'env-7', 'rustfs-secret', pathStyle: true),
            31 => new SqlConnection(DatastoreKind::Postgres, 'private-blog-main.db.ondigitalocean.com', 25060, 'env_8', 'env_8', 'DO/generated+pass', 'main', true),
            32 => new SqlConnection(DatastoreKind::Postgres, 'private-blog-reports.db.ondigitalocean.com', 25060, 'env_8_reports', 'env_8_0b7ec688', 'do-reports-pass', 'reports', false),
            33 => null,
            41 => new SqlConnection(DatastoreKind::Postgres, 'postgres-4.oc-datastores.svc.cluster.local', 5432, 'env_9', 'env_9', 'swap-main-pass', 'main', false),
            42 => new SqlConnection(DatastoreKind::Mysql, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_9_reports', 'env_9_0b7ec688', 'swap-reports-pass', 'reports', true),
        ], DatastoreAttachment::query()->with('datastore')->orderBy('id')->get()
            ->mapWithKeys(fn (DatastoreAttachment $attachment) => [$attachment->id => Provisioners::of($attachment->datastore)->connection($attachment)])
            ->all());
    }

    /**
     * Three environments of one Laravel app. "shop" has every local shape, "blog" has managed Postgres before and
     * after the apply, and "swap" moves the default from one database to another.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $cluster = Cluster::factory()->create(['id' => 1, 'name' => 'local', 'kind' => ClusterKind::Local]);
        $application = Application::factory()->for($cluster)->create(['id' => 1, 'name' => 'shop']);
        [$shop, $blog, $swap] = array_map(
            fn (array $row) => Environment::factory()->for($application)->create($row),
            [['id' => 7, 'name' => 'shop'], ['id' => 8, 'name' => 'blog'], ['id' => 9, 'name' => 'swap']],
        );

        $mysql = $this->datastore(3, DatastoreKind::Mysql, DatastoreSource::Standard, $cluster);
        $postgres = $this->datastore(4, DatastoreKind::Postgres, DatastoreSource::Standard, $cluster);
        $valkey = $this->datastore(5, DatastoreKind::Valkey, DatastoreSource::Standard, $cluster);
        $rustfs = $this->datastore(6, DatastoreKind::Rustfs, DatastoreSource::Standard, $cluster);
        $snapshot = [];

        // shop: the default database, two named databases, Valkey, and RustFS. The password needs URL encoding.
        $shopAttachments = [
            $this->attachment(21, $mysql, $shop, 'main', true, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_7', 'env_7', 'mysql-main-pass'),
            $this->attachment(22, $postgres, $shop, 'reports', false, 'postgres-4.oc-datastores.svc.cluster.local', 5432, 'env_7_reports', 'env_7_0b7ec688', 'p/ss+w@rd:1%'),
            $this->attachment(23, $mysql, $shop, 'analytics', false, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_7_analytics', 'env_7_66d9b558', 'analytics-pass'),
            $this->attachment(24, $valkey, $shop, null, false, null, null, null, null, 'valkey-pass'),
            $this->attachment(25, $rustfs, $shop, null, false, null, null, 'env-7', 'env-7', 'rustfs-secret'),
        ];
        $this->write($shopAttachments);
        $snapshot['shop'] = $this->rows($shop);
        $this->write($shopAttachments);
        $snapshot['shop after a second write'] = $this->rows($shop);
        $snapshot['shop config lines'] = collect($shopAttachments)
            ->mapWithKeys(fn (DatastoreAttachment $attachment) => [$attachment->id => AttachmentEnv::configLine($attachment->fresh())])
            ->all();

        // blog: managed Postgres has no address and no password before its apply, so it has no env values yet.
        $main = $this->attachment(31, $this->datastore(9, DatastoreKind::Postgres, DatastoreSource::Managed, $cluster), $blog, 'main', true, null, null, 'env_8', 'env_8', null);
        $reports = $this->attachment(32, $this->datastore(10, DatastoreKind::Postgres, DatastoreSource::Managed, $cluster), $blog, 'reports', false, null, null, 'env_8_reports', 'env_8_0b7ec688', null);
        $archive = $this->attachment(33, $this->datastore(11, DatastoreKind::Postgres, DatastoreSource::Managed, $cluster), $blog, 'archive', false, null, null, 'env_8_archive', 'env_8_ebfb55f4', null);
        $this->write([$main, $reports, $archive]);
        $snapshot['blog before the apply'] = $this->rows($blog);

        // The apply stores the outputs of OpenTofu, then writes the env, like ProvisionManagedDatabase does.
        $main->update(['host' => 'private-blog-main.db.ondigitalocean.com', 'port' => 25060, 'password' => 'DO/generated+pass']);
        $reports->update(['host' => 'private-blog-reports.db.ondigitalocean.com', 'port' => 25060, 'password' => 'do-reports-pass']);
        $this->write([$main, $reports]);
        $snapshot['blog after the apply'] = $this->rows($blog);

        // swap: the old default gets its URL value and the new default gets DB_*, like SetDefaultDatabase does.
        $old = $this->attachment(41, $postgres, $swap, 'main', true, 'postgres-4.oc-datastores.svc.cluster.local', 5432, 'env_9', 'env_9', 'swap-main-pass');
        $new = $this->attachment(42, $mysql, $swap, 'reports', false, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_9_reports', 'env_9_0b7ec688', 'swap-reports-pass');
        $this->write([$old, $new]);
        $snapshot['swap before the default moves'] = $this->rows($swap);
        $old->update(['is_default' => false]);
        $new->update(['is_default' => true]);
        $this->write([$old, $new]);
        $snapshot['swap after the default moves'] = $this->rows($swap);

        // The values that the env Secret of the next deployment holds.
        foreach (['shop' => $shop, 'blog' => $blog, 'swap' => $swap] as $label => $environment) {
            $snapshot["runtime {$label}"] = EnvironmentVariables::runtime($environment->fresh());
        }

        return $snapshot;
    }

    private function datastore(int $id, DatastoreKind $kind, DatastoreSource $source, Cluster $cluster): Datastore
    {
        return Datastore::factory()->for($cluster)->create(['id' => $id, 'kind' => $kind, 'source' => $source]);
    }

    private function attachment(int $id, Datastore $datastore, Environment $environment, ?string $name, bool $isDefault, ?string $host, ?int $port, ?string $database, ?string $username, ?string $password): DatastoreAttachment
    {
        return DatastoreAttachment::factory()->create([
            'id' => $id,
            'datastore_id' => $datastore->id,
            'environment_id' => $environment->id,
            'name' => $name,
            'is_default' => $isDefault,
            'host' => $host,
            'port' => $port,
            'database_name' => $database,
            'username' => $username,
            'password' => $password,
        ]);
    }

    /** @param  list<DatastoreAttachment>  $attachments */
    private function write(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            AttachmentEnv::write($attachment->fresh());
        }
    }

    /**
     * Every env row of the environment with each column that leaves the dashboard. The row ids and their order do
     * not: the settings page, the canvas, and the env Secret all sort by key.
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: string, 4: string, 5: ?int}>
     */
    private function rows(Environment $environment): array
    {
        return EnvVar::query()->where('environment_id', $environment->id)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => [$var->key, $var->value, $var->is_secret, $var->available_at->value, $var->source->value, $var->datastore_attachment_id])
            ->all();
    }
}
