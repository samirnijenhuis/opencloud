<?php

namespace Tests\Domains\Applications\Actions;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Builds\Exceptions\DeployKeyRefused;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class CreateApplicationTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_it_creates_production_the_preview_template_the_env_and_the_domains_and_starts_a_deployment(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $processes->lsRemote = self::SHA."\trefs/heads/main\n";
        $owner = User::factory()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);

        $application = app(CreateApplication::class)->execute($owner, [
            'name' => 'shop',
            'repository' => 'acme/shop',
            'cluster_id' => $cluster->id,
            'env' => [
                ['key' => 'MAIL_MAILER', 'value' => 'log'],
                ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_123456', 'is_secret' => true],
                ['key' => 'COMPOSER_AUTH', 'value' => '{"x":1}', 'is_secret' => true, 'available_at' => 'build'],
            ],
            'domains' => ['Shop.Example.com.'],
        ]);

        $this->assertSame([
            ['git', 'ls-remote', '--symref', 'https://github.com/acme/shop.git', 'HEAD'],
            ['git', 'ls-remote', 'https://github.com/acme/shop.git', 'refs/heads/main'],
        ], $processes->commands());
        $this->assertSame(['shop', 'acme/shop', ApplicationType::LARAVEL, 8080], [$application->name, $application->repository, $application->type, $application->fresh()->port]);

        $environments = $application->environments()->orderBy('id')->get()->map(fn (Environment $e) => [
            $e->name, $e->kind, $e->is_protected, $e->branch, $e->deploy_commands,
        ])->all();
        $this->assertSame([
            ['production', EnvironmentKind::PERMANENT, true, 'main', ['php artisan migrate --force']],
            ['preview-template', EnvironmentKind::PREVIEW_TEMPLATE, false, null, ['php artisan migrate --force']],
        ], $environments);

        $production = $application->environments()->where('name', 'production')->sole();
        $defaults = [
            ['worker', 'long_running', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', null, 's', 1, 1, 70],
            ['scheduler', 'scheduled', 'php artisan schedule:run', '* * * * *', 's', null, null, null],
        ];
        $this->assertSame([$defaults, $defaults], $application->environments()->orderBy('id')->get()->map(fn (Environment $e) => self::processes($e))->all());

        $env = $production->envVars()->where('source', EnvVarSource::USER)->orderBy('key')->get()->map(fn (EnvVar $v) => [$v->key, $v->is_secret, $v->available_at, $v->source])->all();
        $this->assertSame([
            ['APP_KEY', true, EnvVarAvailability::RUNTIME, EnvVarSource::USER],
            ['COMPOSER_AUTH', true, EnvVarAvailability::BUILD, EnvVarSource::USER],
            ['MAIL_MAILER', false, EnvVarAvailability::RUNTIME, EnvVarSource::USER],
            ['STRIPE_SECRET', true, EnvVarAvailability::RUNTIME, EnvVarSource::USER],
        ], $env);
        $appKey = $production->envVars()->where('key', 'APP_KEY')->sole()->value;
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $appKey);
        $this->assertSame(32, strlen(base64_decode(substr($appKey, 7))));

        $attachment = $production->datastoreAttachments()->sole();
        $this->assertSame([DatastoreKind::MYSQL, "env_{$production->id}", DatastoreStatus::PENDING], [$attachment->datastore->kind, $attachment->database_name, $attachment->status]);
        $this->assertSame(
            ['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'],
            $production->envVars()->where('source', EnvVarSource::DATASTORE)->orderBy('key')->pluck('key')->all(),
        );
        Queue::assertPushed(ProvisionDatastoreAttachment::class, fn (ProvisionDatastoreAttachment $job) => $job->attachment->is($attachment));

        $this->assertSame(['shop.example.com'], $production->domains()->pluck('hostname')->all());
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->hostname === 'shop.example.com');

        $deployment = $production->deployments()->sole();
        $this->assertSame([self::SHA, DeploymentStatus::QUEUED, $owner->id], [$deployment->commit_sha, $deployment->status, $deployment->triggered_by]);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->is($deployment));

        $this->assertSame(
            [
                'application.created', 'process.added', 'process.added', 'process.added', 'process.added',
                'env_var.created', 'env_var.created', 'env_var.created', 'env_var.created', 'datastore.attached', 'domain.added', 'deployment.started',
            ],
            AuditEvent::orderBy('id')->pluck('action')->all(),
        );
        $audit = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('sk_live_123456', $audit);
        $this->assertStringNotContainsString(substr($appKey, 7), $audit);
    }

    /** @return array<string, array{ApplicationType, string, string}> */
    public static function generatedSecrets(): array
    {
        return [
            'python' => [ApplicationType::PYTHON, 'SECRET_KEY', '/^[0-9a-f]{64}$/'],
            'ruby' => [ApplicationType::RUBY, 'SECRET_KEY_BASE', '/^[0-9a-f]{128}$/'],
        ];
    }

    #[DataProvider('generatedSecrets')]
    public function test_a_new_app_gets_the_generated_secret_of_its_preset_and_the_preview_template_does_not(ApplicationType $type, string $key, string $pattern): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";

        $application = app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => 'api',
            'repository' => 'acme/api',
            'cluster_id' => Cluster::factory()->create()->id,
            'type' => $type,
        ]);

        $vars = $application->environments()->orderBy('id')->get()->map(fn (Environment $e) => $e->envVars()->get()->map(fn (EnvVar $v) => [$v->key, $v->is_secret, $v->available_at])->all())->all();
        $this->assertSame([[[$key, true, EnvVarAvailability::RUNTIME]], []], $vars);
        $this->assertMatchesRegularExpression($pattern, $application->environments()->where('name', 'production')->sole()->envVars()->sole()->value);
    }

    public function test_the_database_choice_attaches_postgres_or_nothing(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";
        $cluster = Cluster::factory()->create();
        $create = fn (string $name, string $database) => app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => $name,
            'repository' => "acme/{$name}",
            'cluster_id' => $cluster->id,
            'database' => $database,
        ])->environments()->where('name', 'production')->sole();

        $postgres = $create('blog', 'postgres');
        $none = $create('docs', 'none');

        $this->assertSame([DatastoreKind::POSTGRES], $postgres->datastoreAttachments()->get()->map(fn ($attachment) => $attachment->datastore->kind)->all());
        $this->assertSame('pgsql', $postgres->envVars()->where('key', 'DB_CONNECTION')->sole()->value);
        $this->assertSame(0, $none->datastoreAttachments()->count());
        $this->assertSame(['APP_KEY'], $none->envVars()->pluck('key')->all());
    }

    public function test_the_settings_and_processes_apply_before_the_first_deployment_and_the_preview_template_keeps_its_size(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";

        $application = app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => 'shop',
            'repository' => 'acme/shop',
            'cluster_id' => Cluster::factory()->create()->id,
            'database' => 'none',
            'processes' => [
                ['name' => 'scheduler', 'kind' => ProcessKind::SCHEDULED, 'command' => 'php artisan schedule:run', 'schedule' => '*/5 * * * *'],
                ['name' => 'horizon', 'kind' => ProcessKind::LONG_RUNNING, 'command' => 'php artisan horizon'],
            ],
            'settings' => [
                'size' => 'l',
                'min_instances' => 2,
                'max_instances' => 6,
                'cpu_target' => 60,
                'build_command' => 'php artisan icons:cache',
                'deploy_commands' => ['php artisan migrate --force', 'php artisan db:seed --force'],
            ],
        ]);
        $production = $application->environments()->where('name', 'production')->sole();
        $template = $application->environments()->where('name', 'preview-template')->sole();

        $this->assertSame(
            ['l', 2, 6, 60, 'php artisan icons:cache', ['php artisan migrate --force', 'php artisan db:seed --force']],
            [$production->size->value, $production->min_instances, $production->max_instances, $production->cpu_target, $production->build_command, $production->deploy_commands],
        );
        $this->assertSame(
            [['scheduler', 'scheduled', 'php artisan schedule:run', '*/5 * * * *', 'l', null, null, null], ['horizon', 'long_running', 'php artisan horizon', null, 'l', 1, 1, 60]],
            self::processes($production),
        );
        $this->assertSame(
            ['s', 1, 1, 70, 'php artisan icons:cache', ['php artisan migrate --force', 'php artisan db:seed --force']],
            [$template->size->value, $template->min_instances, $template->max_instances, $template->cpu_target, $template->build_command, $template->deploy_commands],
        );
        $this->assertSame(
            [['scheduler', 'scheduled', 'php artisan schedule:run', '*/5 * * * *', 's', null, null, null], ['horizon', 'long_running', 'php artisan horizon', null, 's', 1, 1, 70]],
            self::processes($template),
        );
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->environment->is($production));
    }

    public function test_without_a_branch_the_repository_default_branch_is_used(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $processes->symref = "ref: refs/heads/13.x\tHEAD\n".self::SHA."\tHEAD\n";
        $processes->lsRemote = self::SHA."\trefs/heads/13.x\n";

        $application = app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => 'laravel',
            'repository' => 'laravel/laravel',
            'cluster_id' => Cluster::factory()->create()->id,
            'database' => 'none',
        ]);

        $this->assertSame('13.x', $application->environments()->where('name', 'production')->sole()->branch);
        $this->assertSame(
            [['git', 'ls-remote', '--symref', 'https://github.com/laravel/laravel.git', 'HEAD'], ['git', 'ls-remote', 'https://github.com/laravel/laravel.git', 'refs/heads/13.x']],
            array_slice(array_column($processes->ran, 'command'), 0, 2),
        );
    }

    public function test_a_given_app_key_replaces_the_generated_one(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/production\n";
        $cluster = Cluster::factory()->create();

        $application = app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => 'shop',
            'repository' => 'acme/shop',
            'cluster_id' => $cluster->id,
            'branch' => 'production',
            'env' => [['key' => 'APP_KEY', 'value' => 'base64:mine', 'is_secret' => true]],
        ]);

        $production = $application->environments()->where('name', 'production')->sole();
        $this->assertSame(['base64:mine'], $production->envVars()->where('key', 'APP_KEY')->get()->pluck('value')->all());
        $this->assertSame('production', $production->branch);
    }

    public function test_a_dockerfile_app_gets_no_app_key_no_deploy_commands_and_only_the_processes_it_asks_for(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";
        $cluster = Cluster::factory()->create();
        $create = fn (string $name, array $extra) => app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => $name,
            'repository' => "acme/{$name}",
            'cluster_id' => $cluster->id,
            'type' => 'dockerfile',
            'port' => 3000,
            ...$extra,
        ]);

        $application = $create('site', []);
        $worker = $create('jobs', ['processes' => [['name' => 'queue', 'kind' => ProcessKind::LONG_RUNNING, 'command' => 'node queue.js']]]);

        $production = $application->environments()->where('name', 'production')->sole();
        $this->assertSame([ApplicationType::DOCKERFILE, 3000], [$application->type, $application->port]);
        $this->assertSame([[], '/'], [$production->deploy_commands, $production->health_path]);
        $this->assertSame(0, $production->envVars()->count());
        $this->assertSame([], self::processes($production));
        $this->assertSame([['queue', 'long_running', 'node queue.js', null, 's', 1, 1, 70]], self::processes($worker->environments()->where('name', 'production')->sole()));
    }

    public function test_a_new_application_gets_the_port_of_its_preset_unless_it_asks_for_another(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";
        $cluster = Cluster::factory()->create();
        $create = fn (string $name, array $extra) => app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => $name,
            'repository' => "acme/{$name}",
            'cluster_id' => $cluster->id,
            'database' => 'none',
            ...$extra,
        ]);

        $applications = [
            $create('shop', ['type' => 'laravel', 'port' => 3000]),
            $create('api', ['type' => 'node']),
            $create('worker', ['type' => 'node', 'port' => 4000]),
            $create('docs', ['type' => 'static', 'port' => 3000]),
            $create('site', ['type' => 'dockerfile']),
            $create('php', ['type' => 'dockerfile', 'port' => 9000]),
        ];

        $this->assertSame(
            [['shop', 8080], ['api', 3000], ['worker', 4000], ['docs', 8080], ['site', 8080], ['php', 9000]],
            array_map(fn (Application $application) => [$application->name, $application->port], $applications),
        );
    }

    public function test_with_a_deploy_key_it_makes_the_key_takes_main_without_a_branch_and_makes_no_remote_call_and_no_deployment(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $owner = User::factory()->create();
        $cluster = Cluster::factory()->create();
        $create = fn (string $name, array $extra) => app(CreateApplication::class)->execute($owner, [
            'name' => $name,
            'repository' => "https://github.com/acme/{$name}.git",
            'cluster_id' => $cluster->id,
            'database' => 'none',
            'deploy_key' => true,
            ...$extra,
        ]);

        $shop = $create('shop', []);
        $api = $create('api', ['branch' => 'develop']);

        $this->assertSame(['ssh-keygen', 'ssh-keygen'], array_column($processes->commands(), 0));
        $this->assertSame(
            [['shop', FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' opencloud shop', 'main'], ['api', FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' opencloud api', 'develop']],
            array_map(fn (Application $application) => [
                $application->name, $application->deploy_private_key, $application->deploy_public_key,
                $application->environments()->where('name', 'production')->sole()->branch,
            ], [$shop->fresh(), $api->fresh()]),
        );
        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNotPushed(RunDeployment::class);
        $this->assertSame(
            ['application.created', 'application.deploy_key_added'],
            AuditEvent::query()->where('subject_id', $shop->id)->where('subject_type', 'application')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_with_an_own_key_it_checks_the_key_reads_the_repository_over_ssh_before_the_records_and_starts_the_first_deployment(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $processes->lsRemote = self::SHA."\trefs/heads/main\n";
        $owner = User::factory()->create();

        $application = app(CreateApplication::class)->execute($owner, [
            'name' => 'shop',
            'repository' => 'https://github.com/acme/shop.git',
            'cluster_id' => Cluster::factory()->create()->id,
            'database' => 'none',
            'processes' => [],
            'deploy_private_key' => trim(FakeProcesses::PRIVATE_KEY),
        ]);

        $this->assertSame([
            ['ssh-keygen', '-y'],
            ['git', 'ls-remote', '--symref', 'git@github.com:acme/shop.git', 'HEAD'],
            ['git', 'ls-remote', 'git@github.com:acme/shop.git', 'refs/heads/main'],
            ['ssh-keygen', '-y'],
        ], array_map(fn (array $command) => $command[0] === 'git' ? $command : array_slice($command, 0, 2), $processes->commands()));
        $this->assertSame([FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' me@example.com'], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(['main', self::SHA], [$application->environments()->where('name', 'production')->sole()->branch, Deployment::sole()->commit_sha]);
        Queue::assertPushedOn('builds', RunDeployment::class);
        $this->assertSame(
            ['application.created', 'application.deploy_key_added'],
            AuditEvent::query()->where('subject_type', 'application')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['source' => 'own'], AuditEvent::query()->where('action', 'application.deploy_key_added')->sole()->context);
    }

    public function test_an_own_key_that_ssh_keygen_cannot_read_stops_before_any_git_call_and_creates_nothing(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();

        try {
            app(CreateApplication::class)->execute(User::factory()->create(), [
                'name' => 'shop',
                'repository' => 'https://github.com/acme/shop.git',
                'cluster_id' => Cluster::factory()->create()->id,
                'deploy_private_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicKey',
            ]);
            $this->fail('No exception.');
        } catch (ValidationException $e) {
            $this->assertSame(['deploy_private_key' => ['Paste an OpenSSH private key without a passphrase.']], $e->errors());
        }

        $this->assertSame(['ssh-keygen'], array_column($processes->commands(), 0));
        $this->assertSame(0, Application::count());
        Queue::assertNothingPushed();
    }

    public function test_an_own_key_that_github_refuses_creates_nothing(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemoteError = "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n";

        try {
            app(CreateApplication::class)->execute(User::factory()->create(), [
                'name' => 'shop',
                'repository' => 'https://github.com/acme/shop.git',
                'cluster_id' => Cluster::factory()->create()->id,
                'deploy_private_key' => FakeProcesses::PRIVATE_KEY,
            ]);
            $this->fail('No exception.');
        } catch (DeployKeyRefused $e) {
            $this->assertStringEndsWith("\n".DeployKeyRefused::STEP, $e->getMessage());
        }

        $this->assertSame([0, 0], [Application::count(), AuditEvent::count()]);
        Queue::assertNothingPushed();
    }

    public function test_a_missing_branch_creates_nothing(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = '';

        try {
            app(CreateApplication::class)->execute(User::factory()->create(), [
                'name' => 'shop',
                'repository' => 'acme/shop',
                'cluster_id' => Cluster::factory()->create()->id,
                'branch' => 'nope',
            ]);
            $this->fail('No exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('The branch nope does not exist in https://github.com/acme/shop.git.', $e->getMessage());
        }

        $this->assertSame(0, Application::count());
        Queue::assertNothingPushed();
    }

    /** @return list<list<mixed>> */
    private static function processes(Environment $environment): array
    {
        return $environment->processes->map(fn (Process $p) => [
            $p->name, $p->kind->value, $p->command, $p->schedule, $p->size->value, $p->min_instances, $p->max_instances, $p->cpu_target,
        ])->all();
    }
}
