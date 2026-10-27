<?php

namespace Tests\Domains\Backups;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Actions\BackupNow;
use App\Domains\Backups\Actions\RestoreBackup;
use App\Domains\Backups\DatabaseBackups;
use App\Domains\Backups\Enums\BackupStatus;
use App\Domains\Backups\Jobs\BackupDatastore;
use App\Domains\Backups\Models\Backup;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class DatabaseBackupsTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_CLIENT = ['bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'];

    private FakeKubectl $kubectl;

    private Filesystem $data;

    private Datastore $mysql;

    private DatastoreAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-23 03:00:00'));
        $this->kubectl = FakeKubectl::install();
        $this->data = Storage::fake('opencloud-data');
        config(['opencloud.data_path' => $this->data->path('')]);

        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $this->mysql = Datastore::factory()->for($cluster)->create(['kind' => DatastoreKind::MYSQL, 'status' => DatastoreStatus::READY, 'root_password' => 'root-secret-pw']);
        $environment = Environment::factory()->create();
        $this->attachment = DatastoreAttachment::factory()->for($this->mysql)->for($environment)->create([
            'database_name' => "env_{$environment->id}",
            'username' => "env_{$environment->id}",
            'password' => 'user-secret-pw',
            'status' => DatastoreStatus::READY,
        ]);
    }

    public function test_a_backup_streams_a_gzip_dump_into_the_data_folder(): void
    {
        $dump = gzencode("CREATE TABLE notes (id int);\nINSERT INTO notes VALUES (1);\n");
        $this->kubectl->respond('exec', new KubectlResult(0, $dump));

        $backup = app(DatabaseBackups::class)->backup($this->attachment);

        $env = $this->attachment->environment_id;
        $location = "backups/{$this->mysql->id}/{$env}/20261023-030000.sql.gz";
        $this->assertSame(
            [BackupStatus::DONE, 'local', $location, "env_{$env}", strlen($dump), $this->attachment->id],
            [$backup->status, $backup->disk, $backup->location, $backup->database_name, $backup->size, $backup->datastore_attachment_id],
        );
        $this->assertSame($dump, $this->data->get($location));
        $this->assertSame([[
            'exec', "mysql-{$this->mysql->id}-0", '--namespace=oc-datastores', '--container=mysql', '--',
            'bash', '-c', "set -o pipefail; MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces env_{$env} | gzip",
        ]], array_column($this->kubectl->calls, 'args'));
    }

    public function test_two_databases_of_one_environment_on_one_server_keep_their_dumps_apart(): void
    {
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('-- dump')));
        $env = $this->attachment->environment_id;
        $this->attachment->update(['name' => 'main', 'is_default' => true]);
        $reports = DatastoreAttachment::factory()->for($this->mysql)->for($this->attachment->environment)->create([
            'name' => 'reports',
            'database_name' => "env_{$env}_reports",
            'status' => DatastoreStatus::READY,
        ]);

        $main = app(DatabaseBackups::class)->backup($this->attachment);
        $named = app(DatabaseBackups::class)->backup($reports);

        $this->assertSame(
            ["backups/{$this->mysql->id}/{$env}/20261023-030000.sql.gz", "backups/{$this->mysql->id}/{$env}/reports/20261023-030000.sql.gz"],
            [$main->location, $named->location],
        );
        $this->assertSame(["backups/{$this->mysql->id}/{$env}/20261023-030000.sql.gz"], $this->data->files("backups/{$this->mysql->id}/{$env}"));
    }

    public function test_postgres_dumps_without_owners_and_grants(): void
    {
        $this->mysql->update(['kind' => DatastoreKind::POSTGRES]);
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('-- dump')));

        app(DatabaseBackups::class)->backup($this->attachment->fresh());

        $this->assertSame(
            ['bash', '-c', "set -o pipefail; pg_dump --username=postgres --no-owner --no-privileges {$this->attachment->database_name} | gzip"],
            array_slice($this->kubectl->calls[0]['args'], -3),
        );
        $this->assertSame('--container=postgres', $this->kubectl->calls[0]['args'][3]);
    }

    public function test_a_failed_dump_keeps_a_failed_backup_with_the_masked_error_and_no_file(): void
    {
        $this->kubectl->respond('exec', new KubectlResult(2, 'partial', "mysqldump: Got error: 1045: Access denied (using password: root-secret-pw)\ncommand terminated with exit code 2"));

        $backup = app(DatabaseBackups::class)->backup($this->attachment);

        $this->assertSame(BackupStatus::FAILED, $backup->status);
        $this->assertSame("mysqldump: Got error: 1045: Access denied (using password: ********)\ncommand terminated with exit code 2", $backup->error);
        $this->assertFalse($this->data->exists($backup->location));
    }

    public function test_the_newest_seven_finished_backups_of_a_database_stay(): void
    {
        $old = collect(range(1, 8))->map(function (int $day) {
            $location = "backups/old-{$day}.sql.gz";
            $this->data->put($location, "dump {$day}");

            return $this->attachment->backups()->create([
                'datastore_id' => $this->mysql->id,
                'database_name' => $this->attachment->database_name,
                'disk' => 'local',
                'location' => $location,
                'size' => 6,
                'status' => $day === 7 ? BackupStatus::FAILED : BackupStatus::DONE,
            ]);
        });
        $otherDatabase = Backup::factory()->for($this->mysql)->create(['database_name' => 'env_999', 'status' => BackupStatus::DONE]);
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('new')));

        $new = app(DatabaseBackups::class)->backup($this->attachment);

        $this->assertSame(
            [...$old->slice(1)->pluck('id')->all(), $otherDatabase->id, $new->id],
            Backup::query()->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [false, true, true, true, true, true, true, true],
            $old->map(fn (Backup $backup) => $this->data->exists($backup->location))->all(),
        );
        $this->assertSame(9, Backup::count());
    }

    public function test_a_backup_target_receives_the_dump_in_its_bucket_with_path_style_urls(): void
    {
        BackupTarget::factory()->create(['endpoint' => 'https://s3.example.com', 'bucket' => 'oc-backups', 'access_key' => 'AKIA1', 'secret_key' => 'SECRET1']);
        $bucket = Storage::fake('bucket');
        Storage::shouldReceive('build')->once()->with([
            'driver' => 's3',
            'key' => 'AKIA1',
            'secret' => 'SECRET1',
            'region' => 'us-east-1',
            'bucket' => 'oc-backups',
            'endpoint' => 'https://s3.example.com',
            'use_path_style_endpoint' => true,
            'throw' => true,
        ])->andReturn($bucket);
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('dump')));

        $backup = app(DatabaseBackups::class)->backup($this->attachment);

        $this->assertSame(['s3', BackupStatus::DONE], [$backup->disk, $backup->status]);
        $this->assertSame(gzencode('dump'), $bucket->get($backup->location));
    }

    public function test_a_restore_backs_up_the_current_database_then_streams_the_dump_into_an_empty_database(): void
    {
        $owner = User::factory()->create();
        $this->data->put('backups/old.sql.gz', gzencode('INSERT INTO notes VALUES (1);'));
        $backup = $this->attachment->backups()->create([
            'datastore_id' => $this->mysql->id, 'database_name' => $this->attachment->database_name, 'disk' => 'local',
            'location' => 'backups/old.sql.gz', 'size' => 10, 'status' => BackupStatus::DONE,
        ]);
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('current state')));

        app(RestoreBackup::class)->execute($backup, $owner);

        $pod = "mysql-{$this->mysql->id}-0";
        $database = $this->attachment->database_name;
        $calls = $this->kubectl->calls;
        $this->assertCount(3, $calls);
        $this->assertSame('exec', $calls[0]['args'][0]);
        $this->assertSame(['exec', '-i', $pod, '--namespace=oc-datastores', '--container=mysql', '--', ...self::MYSQL_CLIENT], $calls[1]['args']);
        $this->assertSame("DROP DATABASE IF EXISTS `{$database}`;\nCREATE DATABASE `{$database}`;\n", $calls[1]['stdin']);
        $this->assertSame(
            ['exec', '-i', $pod, '--namespace=oc-datastores', '--container=mysql', '--', 'bash', '-c', "set -o pipefail; gunzip | MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql --user=root {$database}"],
            $calls[2]['args'],
        );
        $this->assertSame(gzencode('INSERT INTO notes VALUES (1);'), $calls[2]['stdin']);
        $before = Backup::query()->latest('id')->first();
        $this->assertSame([BackupStatus::DONE, gzencode('current state')], [$before->status, $this->data->get($before->location)]);
        $this->assertSame(['backup.restore_requested', 'backup.restored'], AuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertSame(['datastore_id' => $this->mysql->id, 'database_name' => $database], AuditEvent::latest('id')->first()->context);
    }

    public function test_a_failed_restore_is_in_the_audit_log_with_the_error(): void
    {
        $this->data->put('backups/old.sql.gz', gzencode('broken'));
        $backup = $this->attachment->backups()->create([
            'datastore_id' => $this->mysql->id, 'database_name' => $this->attachment->database_name, 'disk' => 'local',
            'location' => 'backups/old.sql.gz', 'size' => 10, 'status' => BackupStatus::DONE,
        ]);
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('current state')));
        $this->kubectl->respond('exec -i mysql', fn (array $args) => end($args) === self::MYSQL_CLIENT[2]
            ? new KubectlResult(0)
            : new KubectlResult(1, '', 'ERROR 1064 (42000) at line 1: You have an error in your SQL syntax'));

        app(RestoreBackup::class)->execute($backup, User::factory()->create());

        $event = AuditEvent::latest('id')->first();
        $this->assertSame('backup.restore_failed', $event->action);
        $this->assertSame('ERROR 1064 (42000) at line 1: You have an error in your SQL syntax', $event->context['error']);
    }

    public function test_only_a_finished_backup_of_an_attached_database_can_be_restored(): void
    {
        $owner = User::factory()->create();
        $failed = Backup::factory()->for($this->mysql)->create(['database_name' => $this->attachment->database_name, 'status' => BackupStatus::FAILED]);
        $detached = Backup::factory()->for($this->mysql)->create(['database_name' => 'env_999', 'status' => BackupStatus::DONE]);

        $messages = collect([$failed, $detached])->map(function (Backup $backup) use ($owner) {
            try {
                app(RestoreBackup::class)->execute($backup, $owner);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
        })->all();

        $this->assertSame([
            'Only a finished backup can be restored.',
            'The database of this backup is no longer attached. Attach it again to restore it.',
        ], $messages);
        $this->assertSame([], $this->kubectl->calls);
    }

    public function test_back_up_now_dumps_one_database_and_refuses_valkey(): void
    {
        $owner = User::factory()->create();
        $this->kubectl->respond('exec', new KubectlResult(0, gzencode('dump')));
        $valkey = DatastoreAttachment::factory()->for(Datastore::factory()->create(['kind' => DatastoreKind::VALKEY]))->create();

        app(BackupNow::class)->execute($this->attachment, $owner);

        try {
            app(BackupNow::class)->execute($valkey, $owner);
            $this->fail('No exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A valkey datastore has no database to back up.', $e->getMessage());
        }

        $this->assertSame([BackupStatus::DONE], $this->attachment->backups()->pluck('status')->all());
        $this->assertSame(['backup.requested'], AuditEvent::pluck('action')->all());
    }

    public function test_the_datastore_job_backs_up_each_database_and_goes_on_after_a_failure(): void
    {
        $second = DatastoreAttachment::factory()->for($this->mysql)->create(['database_name' => 'env_second', 'username' => 'env_second']);
        $this->kubectl->respond('exec', fn (array $args) => str_contains(end($args), $this->attachment->database_name.' |')
            ? new KubectlResult(1, '', 'mysqldump: Got error: 1049: Unknown database')
            : new KubectlResult(0, gzencode('dump')));

        BackupDatastore::dispatchSync($this->mysql);

        $this->assertSame(
            [[$this->attachment->database_name, BackupStatus::FAILED], ['env_second', BackupStatus::DONE]],
            Backup::orderBy('id')->get()->map(fn (Backup $backup) => [$backup->database_name, $backup->status])->all(),
        );
        $this->assertSame($second->id, Backup::latest('id')->value('datastore_attachment_id'));
    }
}
