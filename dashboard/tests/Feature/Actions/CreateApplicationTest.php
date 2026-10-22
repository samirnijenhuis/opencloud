<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateApplication;
use App\Datastores\DatastoreStatus;
use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Jobs\CheckDomain;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\RunDeployment;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);

        $application = app(CreateApplication::class)->handle($owner, [
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

        $this->assertSame([['git', 'ls-remote', 'https://github.com/acme/shop.git', 'refs/heads/main']], $processes->commands());
        $this->assertSame(['shop', 'acme/shop', ApplicationType::Laravel, 8080], [$application->name, $application->repository, $application->type, $application->fresh()->port]);

        $environments = $application->environments()->orderBy('id')->get()->map(fn (Environment $e) => [
            $e->name, $e->kind, $e->is_protected, $e->branch, $e->deploy_commands,
        ])->all();
        $this->assertSame([
            ['production', EnvironmentKind::Permanent, true, 'main', ['php artisan migrate --force']],
            ['preview-template', EnvironmentKind::PreviewTemplate, false, null, ['php artisan migrate --force']],
        ], $environments);

        $production = $application->environments()->where('name', 'production')->sole();
        $env = $production->envVars()->where('source', EnvVarSource::User)->orderBy('key')->get()->map(fn (EnvVar $v) => [$v->key, $v->is_secret, $v->available_at, $v->source])->all();
        $this->assertSame([
            ['APP_KEY', true, EnvVarAvailability::Runtime, EnvVarSource::User],
            ['COMPOSER_AUTH', true, EnvVarAvailability::Build, EnvVarSource::User],
            ['MAIL_MAILER', false, EnvVarAvailability::Runtime, EnvVarSource::User],
            ['STRIPE_SECRET', true, EnvVarAvailability::Runtime, EnvVarSource::User],
        ], $env);
        $appKey = $production->envVars()->where('key', 'APP_KEY')->sole()->value;
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $appKey);
        $this->assertSame(32, strlen(base64_decode(substr($appKey, 7))));

        $attachment = $production->datastoreAttachments()->sole();
        $this->assertSame([DatastoreKind::Mysql, "env_{$production->id}", DatastoreStatus::Pending], [$attachment->datastore->kind, $attachment->database_name, $attachment->status]);
        $this->assertSame(
            ['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'],
            $production->envVars()->where('source', EnvVarSource::Datastore)->orderBy('key')->pluck('key')->all(),
        );
        Queue::assertPushed(ProvisionDatastoreAttachment::class, fn (ProvisionDatastoreAttachment $job) => $job->attachment->is($attachment));

        $this->assertSame(['shop.example.com'], $production->domains()->pluck('hostname')->all());
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->hostname === 'shop.example.com');

        $deployment = $production->deployments()->sole();
        $this->assertSame([self::SHA, DeploymentStatus::Queued, $owner->id], [$deployment->commit_sha, $deployment->status, $deployment->triggered_by]);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->is($deployment));

        $this->assertSame(
            ['application.created', 'env_var.created', 'env_var.created', 'env_var.created', 'env_var.created', 'datastore.attached', 'domain.added', 'deployment.started'],
            AuditEvent::orderBy('id')->pluck('action')->all(),
        );
        $audit = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('sk_live_123456', $audit);
        $this->assertStringNotContainsString(substr($appKey, 7), $audit);
    }

    public function test_the_database_choice_attaches_postgres_or_nothing(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";
        $cluster = Cluster::factory()->create();
        $create = fn (string $name, string $database) => app(CreateApplication::class)->handle(User::factory()->create(), [
            'name' => $name,
            'repository' => "acme/{$name}",
            'cluster_id' => $cluster->id,
            'database' => $database,
        ])->environments()->where('name', 'production')->sole();

        $postgres = $create('blog', 'postgres');
        $none = $create('docs', 'none');

        $this->assertSame([DatastoreKind::Postgres], $postgres->datastoreAttachments()->get()->map(fn ($attachment) => $attachment->datastore->kind)->all());
        $this->assertSame('pgsql', $postgres->envVars()->where('key', 'DB_CONNECTION')->sole()->value);
        $this->assertSame(0, $none->datastoreAttachments()->count());
        $this->assertSame(['APP_KEY'], $none->envVars()->pluck('key')->all());
    }

    public function test_the_settings_apply_before_the_first_deployment_and_the_preview_template_keeps_its_size(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";

        $application = app(CreateApplication::class)->handle(User::factory()->create(), [
            'name' => 'shop',
            'repository' => 'acme/shop',
            'cluster_id' => Cluster::factory()->create()->id,
            'database' => 'none',
            'settings' => [
                'size' => 'l',
                'min_instances' => 2,
                'max_instances' => 6,
                'cpu_target' => 60,
                'build_command' => 'php artisan icons:cache',
                'deploy_commands' => ['php artisan migrate --force', 'php artisan db:seed --force'],
                'worker_enabled' => false,
                'scheduler_enabled' => true,
            ],
        ]);
        $production = $application->environments()->where('name', 'production')->sole();
        $template = $application->environments()->where('name', 'preview-template')->sole();

        $this->assertSame(
            ['l', 2, 6, 60, 'php artisan icons:cache', ['php artisan migrate --force', 'php artisan db:seed --force'], false, true],
            [$production->size->value, $production->min_instances, $production->max_instances, $production->cpu_target, $production->build_command, $production->deploy_commands, $production->worker_enabled, $production->scheduler_enabled],
        );
        $this->assertSame(
            ['s', 1, 1, 70, 'php artisan icons:cache', ['php artisan migrate --force', 'php artisan db:seed --force'], false, true],
            [$template->size->value, $template->min_instances, $template->max_instances, $template->cpu_target, $template->build_command, $template->deploy_commands, $template->worker_enabled, $template->scheduler_enabled],
        );
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->environment->is($production));
    }

    public function test_a_given_app_key_replaces_the_generated_one(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/production\n";
        $cluster = Cluster::factory()->create();

        $application = app(CreateApplication::class)->handle(User::factory()->create(), [
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

    public function test_a_dockerfile_app_gets_no_app_key_and_no_deploy_commands(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";

        $application = app(CreateApplication::class)->handle(User::factory()->create(), [
            'name' => 'site',
            'repository' => 'acme/site',
            'cluster_id' => Cluster::factory()->create()->id,
            'type' => 'dockerfile',
            'port' => 3000,
        ]);

        $production = $application->environments()->where('name', 'production')->sole();
        $this->assertSame([ApplicationType::Dockerfile, 3000], [$application->type, $application->port]);
        $this->assertSame([[], '/'], [$production->deploy_commands, $production->health_path]);
        $this->assertSame(0, $production->envVars()->count());
    }

    public function test_a_missing_branch_creates_nothing(): void
    {
        Queue::fake();
        FakeProcesses::install()->lsRemote = '';

        try {
            app(CreateApplication::class)->handle(User::factory()->create(), [
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
}
