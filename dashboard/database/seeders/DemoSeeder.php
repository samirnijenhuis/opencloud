<?php

namespace Database\Seeders;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Enums\EnvProfileKind;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Enums\BackupStatus;
use App\Domains\Backups\Models\Backup;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\CommandRunStatus;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fills a fresh database so every screen has something to show.
 * Run it with: php artisan migrate:fresh --seed --seeder=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Olivia Owner', 'email' => 'owner@example.com']);
        User::factory()->admin()->create(['name' => 'Adam Admin', 'email' => 'admin@example.com']);
        $developer = User::factory()->create(['name' => 'Dana Developer', 'email' => 'developer@example.com']);

        $cluster = Cluster::create([
            'name' => 'local',
            'kind' => ClusterKind::LOCAL,
            'ingress_ip' => '203.0.113.10',
            'parts_version' => '0.1.0',
            'status' => 'ready',
        ]);

        $shop = Application::create(['name' => 'shop', 'repository' => 'https://github.com/acme/shop.git', 'cluster_id' => $cluster->id, 'type' => ApplicationType::LARAVEL]);
        $docs = Application::create(['name' => 'docs-site', 'repository' => 'https://github.com/acme/docs-site.git', 'cluster_id' => $cluster->id, 'type' => ApplicationType::NODE, 'env_profile' => EnvProfileKind::GENERIC, 'port' => 3000]);

        $production = $this->environment($shop, 'production', EnvironmentKind::PERMANENT, protected: true, attributes: [
            'size' => Size::M,
            'min_instances' => 2,
            'max_instances' => 5,
            'deploy_commands' => ['php artisan migrate --force'],
        ]);
        $staging = $this->environment($shop, 'staging', EnvironmentKind::PERMANENT);
        $preview = $this->environment($shop, 'pr-12', EnvironmentKind::PREVIEW, attributes: ['pr_number' => 12, 'branch' => 'feature/checkout']);
        $docsProduction = $this->environment($docs, 'production', EnvironmentKind::PERMANENT, protected: true, attributes: [
            'deploy_commands' => [],
            'health_path' => '/',
        ]);

        $this->shopDeployments($production, $owner, $developer);
        $this->commandRuns($production, $owner);
        $this->deployment($staging, DeploymentStatus::QUEUED, $developer, 'c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c0ffe', 4, null);
        $this->deployment($preview, DeploymentStatus::DEPLOYING, $developer, '5a1ad5a1ad5a1ad5a1ad5a1ad5a1ad5a1ad5a1ad', 1, $this->log('deploying'));
        $this->docsDeployments($docsProduction, $owner);

        $this->envValues($production, $docsProduction);
        $this->domains($production, $staging, $docsProduction);
        $this->datastores($cluster, $production, $staging, $owner);

        AuditEvent::record($owner, 'application.created', $shop, ['repository' => $shop->repository, 'type' => 'laravel']);
        AuditEvent::record($owner, 'application.created', $docs, ['repository' => $docs->repository, 'type' => 'node']);
        AuditEvent::record($owner, 'domain.added', Domain::where('hostname', 'shop.example.com')->sole(), ['hostname' => 'shop.example.com']);
        AuditEvent::record($developer, 'deployment.started', Deployment::first(), ['commit_sha' => Deployment::first()->commit_sha]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function environment(Application $application, string $name, EnvironmentKind $kind, bool $protected = false, array $attributes = []): Environment
    {
        $environment = $application->environments()->create([
            'name' => $name,
            'kind' => $kind,
            'is_protected' => $protected,
            'branch' => 'main',
            ...$attributes,
        ]);

        $environments = [$environment];

        if ($name === 'production') {
            $environments[] = $application->environments()->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'auto_deploy' => false]);
        }

        foreach ($environments as $each) {
            foreach ($application->type->processes() as $process) {
                $each->processes()->create($process);
            }
        }

        return $environment;
    }

    private function commandRuns(Environment $production, User $owner): void
    {
        $live = $production->deployments()->where('status', DeploymentStatus::LIVE)->sole();
        $runs = [
            ['php artisan migrate:status', CommandRunStatus::SUCCEEDED, 0, 90, 4, "  Migration name .......................... Batch / Status\n  0001_01_01_000000_create_users_table ......... [1] Ran\n  0001_01_01_000001_create_cache_table ......... [1] Ran\n  2026_10_02_101500_create_orders_table ........ [2] Ran\n"],
            ['php artisan queue:retry all', CommandRunStatus::FAILED, 1, 30, 2, "\n   ERROR  No failed jobs matched the given IDs.\n\n"],
        ];

        foreach ($runs as [$command, $status, $exitCode, $minutesAgo, $seconds, $output]) {
            $run = $production->commandRuns()->create([
                'user_id' => $owner->id,
                'deployment_id' => $live->id,
                'command' => $command,
                'status' => $status,
                'exit_code' => $exitCode,
                'output' => $output,
                'started_at' => now()->subMinutes($minutesAgo),
                'finished_at' => now()->subMinutes($minutesAgo)->addSeconds($seconds),
            ]);
            $run->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        }
    }

    private function shopDeployments(Environment $production, User $owner, User $developer): void
    {
        $this->deployment($production, DeploymentStatus::SUPERSEDED, $owner, '1111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 3 * 1440, $this->log('live'));
        $this->deployment($production, DeploymentStatus::FAILED, $developer, '2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 1440, $this->log('failed'), 'The build failed. Nothing changed in production.');
        $this->deployment($production, DeploymentStatus::LIVE, $owner, '3333333cccccccccccccccccccccccccccccccc', 95, $this->log('live'), 'The health check answered slowly. It took 8 seconds.');
    }

    private function docsDeployments(Environment $production, User $owner): void
    {
        $this->deployment($production, DeploymentStatus::FAILED, $owner, '4444444dddddddddddddddddddddddddddddddd', 60, $this->log('failed'));
        $this->deployment($production, DeploymentStatus::BUILDING, $owner, '5555555eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 2, $this->log('building'));
    }

    private function deployment(Environment $environment, DeploymentStatus $status, User $by, string $sha, int $minutesAgo, ?string $log, ?string $warning = null): Deployment
    {
        $createdAt = Carbon::now()->subMinutes($minutesAgo);
        $started = in_array($status, [DeploymentStatus::QUEUED], true) ? null : $createdAt->copy()->addSeconds(5);
        $finished = in_array($status, [DeploymentStatus::LIVE, DeploymentStatus::FAILED, DeploymentStatus::SUPERSEDED], true) ? $createdAt->copy()->addSeconds(140) : null;

        $deployment = new Deployment([
            'environment_id' => $environment->id,
            'commit_sha' => $sha,
            'status' => $status,
            'triggered_by' => $by->id,
            'started_at' => $started,
            'finished_at' => $finished,
            'warning' => $warning,
        ]);
        $deployment->log = $log;
        $deployment->created_at = $createdAt;
        $deployment->save();

        return $deployment;
    }

    private function log(string $kind): string
    {
        $time = Carbon::now()->subHour();
        $lines = [
            'Cloning https://github.com/acme/shop.git at 3333333',
            'Detected a Laravel application with PHP 8.4',
            'composer install --no-dev --optimize-autoloader',
            'Installing dependencies from lock file',
            'Package operations: 87 installs, 0 updates, 0 removals',
            'Generating optimized autoload files',
            'npm ci',
            'npm run build',
            'vite v7.1.4 building for production',
            'built in 4.12s',
        ];

        if ($kind === 'failed') {
            $lines[] = 'ERROR: Module not found: Error: Can not resolve "./resources/js/checkout.tsx"';
            $lines[] = 'The build command exited with code 1';
        }

        if ($kind === 'building') {
            $lines = array_slice($lines, 0, 6);
        }

        if (in_array($kind, ['live', 'deploying'], true)) {
            $lines = [
                ...$lines,
                'Pushing the image to the cluster registry',
                'Image ready: registry.local/shop:3333333',
                'Running deploy command: php artisan migrate --force',
                'Nothing to migrate.',
                'Rolling out 2 instances',
            ];
        }

        if ($kind === 'live') {
            $lines[] = 'Health check passed on /up';
            $lines[] = 'The new version is live';
        }

        return collect($lines)
            ->map(fn (string $line, int $index) => $time->copy()->addSeconds($index * 7)->toIso8601ZuluString().' '.$line)
            ->implode("\n")."\n";
    }

    private function envValues(Environment $production, Environment $docsProduction): void
    {
        $values = [
            [$production, 'APP_ENV', 'production', false, EnvVarAvailability::BOTH],
            [$production, 'APP_URL', 'https://shop.example.com', false, EnvVarAvailability::RUNTIME],
            [$production, 'MAIL_MAILER', 'smtp', false, EnvVarAvailability::RUNTIME],
            [$production, 'APP_KEY', 'base64:'.base64_encode(str_repeat('k', 32)), true, EnvVarAvailability::RUNTIME],
            [$production, 'STRIPE_SECRET', 'sk_live_demo_value_do_not_use', true, EnvVarAvailability::RUNTIME],
            [$docsProduction, 'NODE_ENV', 'production', false, EnvVarAvailability::BOTH],
        ];

        foreach ($values as [$environment, $key, $value, $secret, $availability]) {
            $environment->envVars()->create(['key' => $key, 'value' => $value, 'is_secret' => $secret, 'available_at' => $availability]);
        }

    }

    private function datastores(Cluster $cluster, Environment $production, Environment $staging, User $owner): void
    {
        $mysql = $this->attachment($cluster, $production, DatastoreKind::MYSQL, DatastoreStatus::READY, 'shop_production', name: 'main');
        $reports = $this->attachment($cluster, $production, DatastoreKind::POSTGRES, DatastoreStatus::READY, 'shop_production_reports', name: 'reports');
        $this->attachment($cluster, $production, DatastoreKind::VALKEY, DatastoreStatus::PROVISIONING, null);
        $this->attachment($cluster, $production, DatastoreKind::RUSTFS, DatastoreStatus::FAILED, 'shop-production', 'The bucket could not be created. The storage server did not answer.', name: 'main');
        $this->attachment($cluster, $staging, DatastoreKind::POSTGRES, DatastoreStatus::PENDING, 'shop_staging', name: 'main');

        $this->envKeys($mysql, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'mysql.shop.svc', 'DB_PASSWORD' => 'demo-database-password']);
        $this->envKeys($reports, ['REPORTS_DB_URL' => 'postgres://shop_production_reports:demo-database-password@postgres.shop.svc:5432/shop_production_reports']);
        $this->envKeys($production->datastoreAttachments()->whereHas('datastore', fn ($query) => $query->where('kind', DatastoreKind::VALKEY))->sole(), ['REDIS_HOST' => 'valkey.shop.svc', 'REDIS_PASSWORD' => 'demo-valkey-password']);

        $backups = [
            [BackupStatus::DONE, 'local', 5 * 1440 + 120, 3_145_728, null],
            [BackupStatus::DONE, 's3', 3 * 1440 + 120, 3_250_000, null],
            [BackupStatus::FAILED, 's3', 1440 + 120, null, 'The upload to the backup target failed.'],
            [BackupStatus::DONE, 's3', 120, 3_410_000, null],
            [BackupStatus::RUNNING, 's3', 1, null, null],
        ];

        foreach ($backups as [$status, $disk, $minutesAgo, $size, $error]) {
            $backup = new Backup([
                'datastore_id' => $mysql->datastore_id,
                'datastore_attachment_id' => $mysql->id,
                'database_name' => $mysql->database_name,
                'disk' => $disk,
                'location' => "{$disk}://backups/{$mysql->database_name}-{$minutesAgo}.sql.gz",
                'size' => $size,
                'status' => $status,
                'error' => $error,
            ]);
            $backup->created_at = Carbon::now()->subMinutes($minutesAgo);
            $backup->save();
        }

        $restored = Backup::where('status', BackupStatus::DONE)->orderBy('id')->first();
        AuditEvent::record($owner, 'backup.restore_requested', $restored, ['datastore_id' => $restored->datastore_id, 'database_name' => $restored->database_name]);
        AuditEvent::record($owner, 'backup.restore_failed', $restored, ['datastore_id' => $restored->datastore_id, 'database_name' => $restored->database_name, 'error' => 'The dump is not a valid gzip file.']);
    }

    /** The first database or bucket of an environment with a name is the default of its family. */
    private function attachment(Cluster $cluster, Environment $environment, DatastoreKind $kind, DatastoreStatus $status, ?string $databaseName, ?string $error = null, ?string $name = null): DatastoreAttachment
    {
        $datastore = Datastore::firstOrCreate(
            ['cluster_id' => $cluster->id, 'kind' => $kind],
            ['version' => '1', 'status' => DatastoreStatus::READY->value, 'root_password' => 'demo-root-password'],
        );

        return $environment->datastoreAttachments()->create([
            'datastore_id' => $datastore->id,
            'family' => $kind->family(),
            'name' => $name,
            'is_default' => $name !== null && ! $environment->datastoreAttachments()->where('family', $kind->family())->where('is_default', true)->exists(),
            'host' => $kind->family() === ResourceFamily::DATABASE ? "{$kind->value}.shop.svc" : null,
            'port' => ['mysql' => 3306, 'postgres' => 5432][$kind->value] ?? null,
            'database_name' => $databaseName,
            'username' => $databaseName,
            'password' => 'demo-attachment-password',
            'status' => $status,
            'error' => $error,
        ]);
    }

    /** @param  array<string, string>  $values */
    private function envKeys(DatastoreAttachment $attachment, array $values): void
    {
        foreach ($values as $key => $value) {
            $attachment->environment->envVars()->create([
                'key' => $key,
                'value' => $value,
                'is_secret' => str_ends_with($key, 'PASSWORD') || str_ends_with($key, '_URL'),
                'source' => EnvVarSource::DATASTORE,
                'datastore_attachment_id' => $attachment->id,
            ]);
        }
    }

    private function domains(Environment $production, Environment $staging, Environment $docsProduction): void
    {
        $production->domains()->create([
            'hostname' => 'shop-production.203.0.113.10.sslip.io',
            'generated' => true,
            'dns_status' => DnsStatus::OK,
            'certificate_status' => 'issued',
            'dns_found' => ['203.0.113.10'],
            'dns_checked_at' => now()->subMinutes(3),
        ]);

        $rows = [
            [$production, 'shop.example.com', DnsStatus::OK, 'issued', ['203.0.113.10']],
            [$production, 'www.shop.example.com', DnsStatus::WRONG, 'pending', ['198.51.100.7']],
            [$production, 'cdn.shop.example.com', DnsStatus::PROXIED, 'issued', ['104.16.1.1', '104.16.2.1']],
            [$staging, 'staging.shop.example.com', DnsStatus::MISSING, 'pending', []],
            [$docsProduction, 'docs.example.org', DnsStatus::PENDING, 'pending', null],
        ];

        foreach ($rows as [$environment, $hostname, $status, $certificate, $found]) {
            $environment->domains()->create([
                'hostname' => $hostname,
                'dns_status' => $status,
                'certificate_status' => $certificate,
                'dns_found' => $found,
                'dns_checked_at' => $found === null ? null : now()->subMinutes(3),
            ]);
        }
    }
}
