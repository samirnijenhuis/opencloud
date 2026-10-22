<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateApplication;
use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Jobs\CheckDomain;
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
        $env = $production->envVars()->orderBy('key')->get()->map(fn (EnvVar $v) => [$v->key, $v->is_secret, $v->available_at, $v->source])->all();
        $this->assertSame([
            ['APP_KEY', true, EnvVarAvailability::Runtime, EnvVarSource::User],
            ['COMPOSER_AUTH', true, EnvVarAvailability::Build, EnvVarSource::User],
            ['MAIL_MAILER', false, EnvVarAvailability::Runtime, EnvVarSource::User],
            ['STRIPE_SECRET', true, EnvVarAvailability::Runtime, EnvVarSource::User],
        ], $env);
        $appKey = $production->envVars()->where('key', 'APP_KEY')->sole()->value;
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $appKey);
        $this->assertSame(32, strlen(base64_decode(substr($appKey, 7))));

        $this->assertSame(['shop.example.com'], $production->domains()->pluck('hostname')->all());
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->hostname === 'shop.example.com');

        $deployment = $production->deployments()->sole();
        $this->assertSame([self::SHA, DeploymentStatus::Queued, $owner->id], [$deployment->commit_sha, $deployment->status, $deployment->triggered_by]);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->is($deployment));

        $this->assertSame(
            ['application.created', 'env_var.created', 'env_var.created', 'env_var.created', 'env_var.created', 'domain.added', 'deployment.started'],
            AuditEvent::orderBy('id')->pluck('action')->all(),
        );
        $audit = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('sk_live_123456', $audit);
        $this->assertStringNotContainsString(substr($appKey, 7), $audit);
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
