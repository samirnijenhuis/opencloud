<?php

namespace Tests\Domains\Backups\Jobs;

use App\Domains\Applications\Models\Application;
use App\Domains\Backups\Jobs\CopyBuckets;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\FakeKubectl;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CopyBucketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_buckets_of_an_environment_share_one_nightly_copy_of_their_server_with_the_root_key(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 03:00:00'));
        $kubectl = FakeKubectl::install();
        $environment = Environment::factory()->for(Application::factory()->for(Cluster::factory()->create(['kind' => ClusterKind::LOCAL])))->create();
        $main = app(AttachDatastore::class)->execute($environment, null, DatastoreKind::RUSTFS);
        $uploads = app(AttachDatastore::class)->execute($environment, null, DatastoreKind::RUSTFS, name: 'uploads');
        BackupTarget::factory()->create(['bucket' => 'oc-backups']);
        Queue::fake();

        collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'nightly-backups')->run($this->app);
        $kubectl->calls = [];
        Queue::pushed(CopyBuckets::class)->sole()->handle();

        $id = $main->datastore_id;
        $this->assertSame([$id, $id], [$main->datastore_id, $uploads->datastore_id]);
        $this->assertSame(
            ['Secret/backup-target', "Job/rustfs-{$id}-copy-20261023"],
            array_map(fn (array $manifest) => "{$manifest['kind']}/{$manifest['metadata']['name']}", $kubectl->appliedManifests()),
        );
        $container = collect($kubectl->appliedManifests())->firstWhere('kind', 'Job')['spec']['template']['spec']['containers'][0];
        $this->assertSame(['sync', 'source:', "target:oc-backups/rustfs/{$id}"], $container['args']);
        $this->assertSame(
            ['secretKeyRef' => ['name' => "rustfs-{$id}", 'key' => 'RUSTFS_ACCESS_KEY']],
            collect($container['env'])->firstWhere('name', 'RCLONE_CONFIG_SOURCE_ACCESS_KEY_ID')['valueFrom'],
        );
    }
}
