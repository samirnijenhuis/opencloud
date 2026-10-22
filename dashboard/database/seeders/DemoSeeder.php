<?php

namespace Database\Seeders;

use App\Backups\BackupStatus;
use App\Datastores\DatastoreStatus;
use App\Domains\DnsStatus;
use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Enums\Size;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\User;
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
            'kind' => ClusterKind::Local,
            'ingress_ip' => '203.0.113.10',
            'parts_version' => '0.1.0',
            'status' => 'ready',
        ]);

        $shop = Application::create(['name' => 'shop', 'repository' => 'https://github.com/acme/shop.git', 'cluster_id' => $cluster->id, 'type' => ApplicationType::Laravel]);
        $docs = Application::create(['name' => 'docs-site', 'repository' => 'https://github.com/acme/docs-site.git', 'cluster_id' => $cluster->id, 'type' => ApplicationType::Dockerfile, 'port' => 3000]);

        $production = $this->environment($shop, 'production', EnvironmentKind::Permanent, protected: true, attributes: [
            'size' => Size::M,
            'min_instances' => 2,
            'max_instances' => 5,
            'deploy_commands' => ['php artisan migrate --force'],
        ]);
        $staging = $this->environment($shop, 'staging', EnvironmentKind::Permanent);
        $preview = $this->environment($shop, 'pr-12', EnvironmentKind::Preview, attributes: ['pr_number' => 12, 'branch' => 'feature/checkout']);
        $docsProduction = $this->environment($docs, 'production', EnvironmentKind::Permanent, protected: true, attributes: [
            'deploy_commands' => [],
            'health_path' => '/',
            'worker_enabled' => false,
            'scheduler_enabled' => false,
        ]);

        $this->shopDeployments($production, $owner, $developer);
        $this->deployment($staging, DeploymentStatus::Queued, $developer, 'c0ffee1c0ffee1c0ffee1c0ffee1c0ffee1c0ffe', 4, null);
        $this->deployment($preview, DeploymentStatus::Deploying, $developer, '5a1ad5a1ad5a1ad5a1ad5a1ad5a1ad5a1ad5a1ad', 1, $this->log('deploying'));
        $this->docsDeployments($docsProduction, $owner);

        $this->envValues($production, $docsProduction);
        $this->domains($production, $staging, $docsProduction);
        $this->datastores($cluster, $production, $staging, $owner);

        AuditEvent::record($owner, 'application.created', $shop, ['repository' => $shop->repository, 'type' => 'laravel']);
        AuditEvent::record($owner, 'application.created', $docs, ['repository' => $docs->repository, 'type' => 'dockerfile']);
        AuditEvent::record($owner, 'domain.added', Domain::first(), ['hostname' => 'shop.example.com']);
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

        if ($name === 'production') {
            $application->environments()->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PreviewTemplate, 'auto_deploy' => false]);
        }

        return $environment;
    }

    private function shopDeployments(Environment $production, User $owner, User $developer): void
    {
        $this->deployment($production, DeploymentStatus::Superseded, $owner, '1111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 3 * 1440, $this->log('live'));
        $this->deployment($production, DeploymentStatus::Failed, $developer, '2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 1440, $this->log('failed'), 'The build failed. Nothing changed in production.');
        $this->deployment($production, DeploymentStatus::Live, $owner, '3333333cccccccccccccccccccccccccccccccc', 95, $this->log('live'), 'The health check answered slowly. It took 8 seconds.');
    }

    private function docsDeployments(Environment $production, User $owner): void
    {
        $this->deployment($production, DeploymentStatus::Failed, $owner, '4444444dddddddddddddddddddddddddddddddd', 60, $this->log('failed'));
        $this->deployment($production, DeploymentStatus::Building, $owner, '5555555eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 2, $this->log('building'));
    }

    private function deployment(Environment $environment, DeploymentStatus $status, User $by, string $sha, int $minutesAgo, ?string $log, ?string $warning = null): Deployment
    {
        $createdAt = Carbon::now()->subMinutes($minutesAgo);
        $started = in_array($status, [DeploymentStatus::Queued], true) ? null : $createdAt->copy()->addSeconds(5);
        $finished = in_array($status, [DeploymentStatus::Live, DeploymentStatus::Failed, DeploymentStatus::Superseded], true) ? $createdAt->copy()->addSeconds(140) : null;

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
            [$production, 'APP_ENV', 'production', false, EnvVarAvailability::Both],
            [$production, 'APP_URL', 'https://shop.example.com', false, EnvVarAvailability::Runtime],
            [$production, 'MAIL_MAILER', 'smtp', false, EnvVarAvailability::Runtime],
            [$production, 'APP_KEY', 'base64:'.base64_encode(str_repeat('k', 32)), true, EnvVarAvailability::Runtime],
            [$production, 'STRIPE_SECRET', 'sk_live_demo_value_do_not_use', true, EnvVarAvailability::Runtime],
            [$docsProduction, 'NODE_ENV', 'production', false, EnvVarAvailability::Both],
        ];

        foreach ($values as [$environment, $key, $value, $secret, $availability]) {
            $environment->envVars()->create(['key' => $key, 'value' => $value, 'is_secret' => $secret, 'available_at' => $availability]);
        }

    }

    private function datastores(Cluster $cluster, Environment $production, Environment $staging, User $owner): void
    {
        $mysql = $this->attachment($cluster, $production, DatastoreKind::Mysql, DatastoreStatus::Ready, 'shop_production');
        $this->attachment($cluster, $production, DatastoreKind::Valkey, DatastoreStatus::Provisioning, null);
        $this->attachment($cluster, $production, DatastoreKind::Rustfs, DatastoreStatus::Failed, 'shop-production', 'The bucket could not be created. The storage server did not answer.');
        $this->attachment($cluster, $staging, DatastoreKind::Postgres, DatastoreStatus::Pending, 'shop_staging');

        $this->envKeys($mysql, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'mysql.shop.svc', 'DB_PASSWORD' => 'demo-database-password']);
        $this->envKeys($production->datastoreAttachments()->whereHas('datastore', fn ($query) => $query->where('kind', DatastoreKind::Valkey))->sole(), ['REDIS_HOST' => 'valkey.shop.svc', 'REDIS_PASSWORD' => 'demo-valkey-password']);

        $backups = [
            [BackupStatus::Done, 'local', 5 * 1440 + 120, 3_145_728, null],
            [BackupStatus::Done, 's3', 3 * 1440 + 120, 3_250_000, null],
            [BackupStatus::Failed, 's3', 1440 + 120, null, 'The upload to the backup target failed.'],
            [BackupStatus::Done, 's3', 120, 3_410_000, null],
            [BackupStatus::Running, 's3', 1, null, null],
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

        $restored = Backup::where('status', BackupStatus::Done)->orderBy('id')->first();
        AuditEvent::record($owner, 'backup.restore_requested', $restored, ['datastore_id' => $restored->datastore_id, 'database_name' => $restored->database_name]);
        AuditEvent::record($owner, 'backup.restore_failed', $restored, ['datastore_id' => $restored->datastore_id, 'database_name' => $restored->database_name, 'error' => 'The dump is not a valid gzip file.']);
    }

    private function attachment(Cluster $cluster, Environment $environment, DatastoreKind $kind, DatastoreStatus $status, ?string $name, ?string $error = null): DatastoreAttachment
    {
        $datastore = Datastore::firstOrCreate(
            ['cluster_id' => $cluster->id, 'kind' => $kind],
            ['version' => '1', 'status' => DatastoreStatus::Ready->value, 'root_password' => 'demo-root-password'],
        );

        return $environment->datastoreAttachments()->create([
            'datastore_id' => $datastore->id,
            'database_name' => $name,
            'username' => $name,
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
                'is_secret' => str_ends_with($key, 'PASSWORD'),
                'source' => EnvVarSource::Datastore,
                'datastore_attachment_id' => $attachment->id,
            ]);
        }
    }

    private function domains(Environment $production, Environment $staging, Environment $docsProduction): void
    {
        $rows = [
            [$production, 'shop.example.com', DnsStatus::Ok, 'issued', ['203.0.113.10']],
            [$production, 'www.shop.example.com', DnsStatus::Wrong, 'pending', ['198.51.100.7']],
            [$production, 'cdn.shop.example.com', DnsStatus::Proxied, 'issued', ['104.16.1.1', '104.16.2.1']],
            [$staging, 'staging.shop.example.com', DnsStatus::Missing, 'pending', []],
            [$docsProduction, 'docs.example.org', DnsStatus::Pending, 'pending', null],
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
