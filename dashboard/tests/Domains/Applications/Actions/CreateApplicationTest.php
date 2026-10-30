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
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
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

    public function test_it_creates_production_and_the_preview_template_with_the_preset_and_starts_no_deployment(): void
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
        ]);

        $this->assertSame([
            ['git', 'ls-remote', '--symref', 'https://github.com/acme/shop.git', 'HEAD'],
            ['git', 'ls-remote', 'https://github.com/acme/shop.git', 'refs/heads/main'],
        ], $processes->commands());
        $this->assertSame(['shop', 'acme/shop', ApplicationType::LARAVEL, 8080], [$application->name, $application->repository, $application->type, $application->fresh()->port]);

        $environments = $application->environments()->orderBy('id')->get()->map(fn (Environment $e) => [
            $e->name, $e->kind, $e->is_protected, $e->branch, $e->deploy_commands, $e->sleep_after_minutes,
        ])->all();
        $this->assertSame([
            ['production', EnvironmentKind::PERMANENT, true, 'main', ['php artisan migrate --force'], null],
            ['preview-template', EnvironmentKind::PREVIEW_TEMPLATE, false, null, ['php artisan migrate --force'], 15],
        ], $environments);

        $production = $application->environments()->where('name', 'production')->sole();
        $defaults = [
            ['worker', 'long_running', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', null, 's', 1, 1, 70],
            ['scheduler', 'long_running', 'php artisan schedule:work', null, 's', 1, 1, 70],
        ];
        $this->assertSame([$defaults, $defaults], $application->environments()->orderBy('id')->get()->map(fn (Environment $e) => self::processes($e))->all());

        $env = $production->envVars()->where('source', EnvVarSource::USER)->orderBy('key')->get()->map(fn (EnvVar $v) => [$v->key, $v->is_secret, $v->available_at, $v->source])->all();
        $this->assertSame([
            ['APP_KEY', true, EnvVarAvailability::RUNTIME, EnvVarSource::USER],
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

        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNotPushed(RunDeployment::class);

        $this->assertSame(
            [
                'application.created', 'process.added', 'process.added', 'process.added', 'process.added',
                'env_var.created', 'datastore.attached',
            ],
            AuditEvent::orderBy('id')->pluck('action')->all(),
        );
        $audit = AuditEvent::all()->toJson();
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
        ]);

        $this->assertSame('13.x', $application->environments()->where('name', 'production')->sole()->branch);
        $this->assertSame(
            [['git', 'ls-remote', '--symref', 'https://github.com/laravel/laravel.git', 'HEAD'], ['git', 'ls-remote', 'https://github.com/laravel/laravel.git', 'refs/heads/13.x']],
            array_slice(array_column($processes->ran, 'command'), 0, 2),
        );
    }

    public function test_a_dockerfile_app_gets_no_processes_no_app_key_no_database_and_no_deploy_commands(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";

        $application = app(CreateApplication::class)->execute(User::factory()->create(), [
            'name' => 'site',
            'repository' => 'acme/site',
            'cluster_id' => Cluster::factory()->create()->id,
            'type' => 'dockerfile',
            'port' => 3000,
        ]);

        $environments = $application->environments()->orderBy('id')->get();
        $this->assertSame([ApplicationType::DOCKERFILE, 3000], [$application->type, $application->port]);
        $this->assertSame([[[], '/'], [[], '/']], $environments->map(fn (Environment $e) => [$e->deploy_commands, $e->health_path])->all());
        $this->assertSame([0, 0], $environments->map(fn (Environment $e) => $e->envVars()->count())->all());
        $this->assertSame([[], []], $environments->map(fn (Environment $e) => self::processes($e))->all());
        $this->assertSame(0, $environments->sum(fn (Environment $e) => $e->datastoreAttachments()->count()));
        $this->assertSame(0, Deployment::query()->count());
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

    public function test_with_an_own_key_it_checks_the_key_reads_the_repository_over_ssh_before_the_records_and_starts_no_deployment(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $processes->lsRemote = self::SHA."\trefs/heads/main\n";
        $owner = User::factory()->create();

        $application = app(CreateApplication::class)->execute($owner, [
            'name' => 'shop',
            'repository' => 'https://github.com/acme/shop.git',
            'cluster_id' => Cluster::factory()->create()->id,
            'deploy_private_key' => trim(FakeProcesses::PRIVATE_KEY),
        ]);

        $this->assertSame([
            ['ssh-keygen', '-y'],
            ['git', 'ls-remote', '--symref', 'git@github.com:acme/shop.git', 'HEAD'],
            ['git', 'ls-remote', 'git@github.com:acme/shop.git', 'refs/heads/main'],
            ['ssh-keygen', '-y'],
        ], array_map(fn (array $command) => $command[0] === 'git' ? $command : array_slice($command, 0, 2), $processes->commands()));
        $this->assertSame([FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' me@example.com'], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame('main', $application->environments()->where('name', 'production')->sole()->branch);
        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNotPushed(RunDeployment::class);
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
