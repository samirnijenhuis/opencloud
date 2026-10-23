<?php

namespace Tests\Feature\Backups;

use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Jobs\BackupDashboard;
use App\Jobs\BackupDatastore;
use App\Jobs\CopyBuckets;
use App\Kube\FakeKubectl;
use App\Models\BackupTarget;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** VACUUM cannot run inside the transaction of RefreshDatabase, so this class migrates for each test. */
class NightlyBackupsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_dashboard_database_is_copied_with_vacuum_into_and_seven_copies_stay(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 03:00:00'));
        $data = Storage::fake('opencloud-data');
        config(['opencloud.data_path' => $data->path('')]);
        User::factory()->create(['email' => 'owner@example.com']);
        foreach (range(15, 22) as $day) {
            $data->put("backups/dashboard/202610{$day}-030000.sqlite.gz", 'old');
        }

        BackupDashboard::dispatchSync();

        $this->assertSame([
            'backups/dashboard/20261017-030000.sqlite.gz',
            'backups/dashboard/20261018-030000.sqlite.gz',
            'backups/dashboard/20261019-030000.sqlite.gz',
            'backups/dashboard/20261020-030000.sqlite.gz',
            'backups/dashboard/20261021-030000.sqlite.gz',
            'backups/dashboard/20261022-030000.sqlite.gz',
            'backups/dashboard/20261023-030000.sqlite.gz',
        ], collect($data->files('backups/dashboard'))->sort()->values()->all());

        $copy = gzdecode($data->get('backups/dashboard/20261023-030000.sqlite.gz'));
        $this->assertSame("SQLite format 3\0", substr($copy, 0, 16));
        $this->assertStringContainsString('owner@example.com', $copy);
    }

    public function test_the_dashboard_copy_reaches_the_bucket_as_a_gzip_stream_that_can_be_read_twice(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 03:00:00'));
        BackupTarget::factory()->create();
        User::factory()->create(['email' => 'owner@example.com']);
        $uploads = [];
        $bucket = Mockery::mock(Filesystem::class);
        $bucket->shouldReceive('files')->andReturn([]);
        $bucket->shouldReceive('writeStream')->andReturnUsing(function (string $path, $stream) use (&$uploads) {
            // The S3 client reads the body to hash it for SigV4, then rewinds it to send it.
            $hashed = stream_get_contents($stream);
            $rewound = @rewind($stream);
            $sent = stream_get_contents($stream);
            $uploads[$path] = [$rewound && $sent === $hashed, substr((string) @gzdecode($sent), 0, 16)];

            return true;
        });
        Storage::extend('s3', fn () => $bucket);

        BackupDashboard::dispatchSync();

        $this->assertSame(['backups/dashboard/20261023-030000.sqlite.gz' => [true, "SQLite format 3\0"]], $uploads);
    }

    public function test_the_bucket_copy_starts_an_rclone_job_only_when_a_backup_target_exists(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 03:00:00'));
        $kubectl = FakeKubectl::install();
        $rustfs = Datastore::factory()->for(Cluster::factory()->create(['kind' => ClusterKind::Local]))->create(['kind' => DatastoreKind::Rustfs, 'version' => '1.0.3']);

        CopyBuckets::dispatchSync($rustfs);
        $withoutTarget = $kubectl->calls;
        BackupTarget::factory()->create(['bucket' => 'oc-backups']);
        CopyBuckets::dispatchSync($rustfs);

        $this->assertSame([], $withoutTarget);
        $this->assertSame(
            ['Secret/backup-target', "Job/rustfs-{$rustfs->id}-copy-20261023"],
            array_map(fn (array $manifest) => "{$manifest['kind']}/{$manifest['metadata']['name']}", $kubectl->appliedManifests()),
        );
    }

    public function test_the_scheduler_starts_the_nightly_backups_at_three(): void
    {
        Queue::fake();
        $mysql = Datastore::factory()->create(['kind' => DatastoreKind::Mysql]);
        $postgres = Datastore::factory()->create(['kind' => DatastoreKind::Postgres]);
        $rustfs = Datastore::factory()->create(['kind' => DatastoreKind::Rustfs]);
        Datastore::factory()->create(['kind' => DatastoreKind::Valkey]);
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'nightly-backups');

        $event->run($this->app);

        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame([$mysql->id, $postgres->id], Queue::pushed(BackupDatastore::class)->map(fn (BackupDatastore $job) => $job->datastore->id)->all());
        $this->assertSame([$rustfs->id], Queue::pushed(CopyBuckets::class)->map(fn (CopyBuckets $job) => $job->datastore->id)->all());
        Queue::assertPushed(BackupDashboard::class, 1);
    }
}
