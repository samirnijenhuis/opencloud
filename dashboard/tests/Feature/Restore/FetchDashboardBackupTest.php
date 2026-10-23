<?php

namespace Tests\Feature\Restore;

use App\Restore\RestoreState;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Tests\TestCase;

class FetchDashboardBackupTest extends TestCase
{
    private const KEY = 'base64:'.'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private const OTHER_KEY = 'base64:'.'ZmVkY2JhOTg3NjU0MzIxMGZlZGNiYTk4NzY1NDMyMTA=';

    private Filesystem $data;

    private Filesystem $bucket;

    /** @var list<array<string, mixed>> */
    private array $builtConfigs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $this->data = Storage::fake('opencloud-data');
        config(['opencloud.data_path' => $this->data->path('data')]);
        $this->bucket = Storage::fake('bucket');
        Storage::extend('s3', function ($app, array $config) {
            $this->builtConfigs[] = $config;

            return $this->bucket;
        });
    }

    public function test_the_newest_copy_comes_back_with_its_key_and_without_the_jobs_and_locks_of_the_lost_server(): void
    {
        $this->bucket->put('backups/dashboard/20261021-030000.sqlite.gz', $this->copy(self::KEY, 'monday@example.com'));
        $this->bucket->put('backups/dashboard/20261023-030000.sqlite.gz', $this->copy(self::KEY, 'wednesday@example.com'));
        $this->bucket->put('backups/dashboard/20261022-030000.sqlite.gz', $this->copy(self::KEY, 'tuesday@example.com'));
        $this->bucket->put('backups/dashboard/notes.txt', 'not a copy');

        $this->fetch($this->restoreFile())
            ->expectsOutput('Restored the dashboard database from backups/dashboard/20261023-030000.sqlite.gz in the bucket oc-backups.')
            ->assertExitCode(0);

        $database = new PDO('sqlite:'.$this->data->path('data/database.sqlite'));
        $this->assertSame(['wednesday@example.com'], $database->query('select email from users')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([0, 0], [(int) $database->query('select count(*) from jobs')->fetchColumn(), (int) $database->query('select count(*) from cache_locks')->fetchColumn()]);
        $this->assertSame(self::KEY, $this->data->get('APP_KEY'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->data->path('APP_KEY'))), -4));
        $this->assertSame('2026-10-23T12:00:00Z', RestoreState::load()->startedAt->toIso8601ZuluString());
        $this->assertSame(
            ['http://host.example.test:19000', 'oc-backups', 'eu-west-1', false, 'restore-access', 'restore-secret-key'],
            [$this->builtConfigs[0]['endpoint'], $this->builtConfigs[0]['bucket'], $this->builtConfigs[0]['region'], $this->builtConfigs[0]['use_path_style_endpoint'], $this->builtConfigs[0]['key'], $this->builtConfigs[0]['secret']],
        );
    }

    public function test_a_key_that_cannot_read_the_copy_writes_nothing(): void
    {
        $this->bucket->put('backups/dashboard/20261023-030000.sqlite.gz', $this->copy(self::OTHER_KEY, 'owner@example.com'));

        $this->fetch($this->restoreFile())
            ->expectsOutput('The APP_KEY in the restore file cannot read the secrets in backups/dashboard/20261023-030000.sqlite.gz. Use the APP_KEY of the lost server.')
            ->assertExitCode(1);

        $this->assertSame(['data'], $this->data->allDirectories());
        $this->assertSame([], $this->data->allFiles());
    }

    public function test_a_key_that_is_not_a_laravel_key_is_refused(): void
    {
        $this->bucket->put('backups/dashboard/20261023-030000.sqlite.gz', $this->copy(self::KEY, 'owner@example.com'));

        $this->fetch($this->restoreFile(['APP_KEY' => 'base64:c2hvcnQ=']))
            ->expectsOutput('The APP_KEY in the restore file is not a valid key. Copy it from /var/lib/opencloud/APP_KEY of the lost server. It starts with base64:.')
            ->assertExitCode(1);

        $this->assertSame([], $this->data->allFiles());
    }

    public function test_a_server_that_has_a_dashboard_database_is_refused(): void
    {
        $this->bucket->put('backups/dashboard/20261023-030000.sqlite.gz', $this->copy(self::KEY, 'owner@example.com'));
        $this->data->put('data/database.sqlite', 'the database of this server');

        $this->fetch($this->restoreFile())
            ->expectsOutput("The dashboard database {$this->data->path('data/database.sqlite')} exists. A restore works only on a new server.")
            ->assertExitCode(1);

        $this->assertSame('the database of this server', $this->data->get('data/database.sqlite'));
        $this->assertFalse($this->data->exists('APP_KEY'));
    }

    public function test_a_restore_file_without_the_secret_key_and_the_app_key_is_refused(): void
    {
        $this->fetch($this->restoreFile(['BACKUP_SECRET_KEY' => '', 'APP_KEY' => '']))
            ->expectsOutput('The restore file has no value for BACKUP_SECRET_KEY, APP_KEY.')
            ->assertExitCode(1);

        $this->assertSame([], $this->builtConfigs);
    }

    public function test_an_empty_bucket_is_refused(): void
    {
        $this->fetch($this->restoreFile())
            ->expectsOutput('The bucket has no dashboard backup in backups/dashboard. Check the endpoint and the bucket in the restore file.')
            ->assertExitCode(1);
    }

    public function test_an_error_from_the_bucket_never_shows_the_keys(): void
    {
        Storage::extend('s3', fn () => throw new RuntimeException('Access denied for restore-access with restore-secret-key'));

        $this->fetch($this->restoreFile())
            ->expectsOutput('Access denied for ******** with ********')
            ->assertExitCode(1);
    }

    private function fetch(string $file): mixed
    {
        return $this->artisan('opencloud:fetch-dashboard-backup', [
            'file' => $file,
            '--database' => $this->data->path('data/database.sqlite'),
            '--app-key-file' => $this->data->path('APP_KEY'),
        ]);
    }

    /** @param  array<string, string>  $overrides */
    private function restoreFile(array $overrides = []): string
    {
        $values = [
            'BACKUP_ENDPOINT' => 'http://host.example.test:19000',
            'BACKUP_REGION' => 'eu-west-1',
            'BACKUP_BUCKET' => 'oc-backups',
            'BACKUP_ACCESS_KEY' => 'restore-access',
            'BACKUP_SECRET_KEY' => '"restore-secret-key"',
            'BACKUP_PATH_STYLE' => 'false',
            'APP_KEY' => self::KEY,
            ...$overrides,
        ];
        $path = tempnam(sys_get_temp_dir(), 'restore-env-');
        file_put_contents($path, "# The restore file of the test\n".collect($values)->map(fn (string $value, string $key) => "{$key}={$value}")->implode("\n")."\n");

        return $path;
    }

    /** A gzip SQLite copy like BackupDashboard writes, with one encrypted secret, one user, one queued job, and one lock. */
    private function copy(string $appKey, string $email): string
    {
        $path = tempnam(sys_get_temp_dir(), 'copy-');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('create table backup_targets (id integer primary key, secret_key text)');
        $pdo->exec('create table users (email text)');
        $pdo->exec('create table jobs (id integer primary key, payload text)');
        $pdo->exec('create table cache_locks (key text, owner text)');
        $secret = (new Encrypter(base64_decode(substr($appKey, 7)), 'AES-256-CBC'))->encryptString('bucket-secret');
        $pdo->prepare('insert into backup_targets (secret_key) values (?)')->execute([$secret]);
        $pdo->prepare('insert into users (email) values (?)')->execute([$email]);
        $pdo->exec("insert into jobs (payload) values ('a job of the lost server')");
        $pdo->exec("insert into cache_locks values ('deploy-environment-1', 'a worker of the lost server')");
        $pdo = null;
        $gzip = gzencode(file_get_contents($path));
        unlink($path);

        return $gzip;
    }
}
