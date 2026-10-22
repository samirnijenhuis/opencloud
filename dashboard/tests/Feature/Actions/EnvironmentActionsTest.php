<?php

namespace Tests\Feature\Actions;

use App\Actions\AddDomain;
use App\Actions\DeleteEnvVar;
use App\Actions\ReadLogs;
use App\Actions\RemoveDomain;
use App\Actions\SetEnvVar;
use App\Actions\StartDeployment;
use App\Enums\ClusterKind;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Jobs\CheckDomain;
use App\Jobs\RunDeployment;
use App\Jobs\SyncCaddy;
use App\Kube\FakeKubectl;
use App\Kube\KubectlResult;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class EnvironmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $application = Application::factory()->for($cluster)->create(['repository' => 'acme/shop']);
        $this->environment = Environment::factory()->for($application)->create(['branch' => 'main']);
    }

    public function test_start_deployment_resolves_the_branch_head(): void
    {
        $processes = FakeProcesses::install();
        $processes->lsRemote = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\trefs/heads/main\n";

        $deployment = app(StartDeployment::class)->handle($this->environment, $this->user);

        $this->assertSame([['git', 'ls-remote', 'https://github.com/acme/shop.git', 'refs/heads/main']], $processes->commands());
        $this->assertSame(['GIT_TERMINAL_PROMPT' => '0'], $processes->ran[0]['env']);
        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $deployment->commit_sha);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->is($deployment));
        $this->assertSame(
            ['deployment.started', ['environment_id' => $this->environment->id, 'commit_sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );
    }

    public function test_start_deployment_with_a_sha_runs_no_git_and_refuses_the_preview_template(): void
    {
        $processes = FakeProcesses::install();

        $deployment = app(StartDeployment::class)->handle($this->environment, null, 'cccccccccccccccccccccccccccccccccccccccc');

        $this->assertSame([], $processes->ran);
        $this->assertSame(['cccccccccccccccccccccccccccccccccccccccc', null], [$deployment->commit_sha, $deployment->triggered_by]);

        $template = Environment::factory()->for($this->environment->application)->create(['kind' => EnvironmentKind::PreviewTemplate]);
        $this->expectExceptionObject(new InvalidArgumentException('The preview template holds settings for previews. It is never deployed.'));
        app(StartDeployment::class)->handle($template, $this->user, 'cccccccccccccccccccccccccccccccccccccccc');
    }

    public function test_set_env_var_keeps_a_secret_when_the_new_value_is_null(): void
    {
        $set = app(SetEnvVar::class);

        $set->handle($this->environment, $this->user, 'STRIPE_SECRET', 'sk_live_1', true, EnvVarAvailability::Runtime);
        $kept = $set->handle($this->environment, $this->user, 'STRIPE_SECRET', null, true, EnvVarAvailability::Both);
        $set->handle($this->environment, $this->user, 'APP_NAME', 'Shop', false, EnvVarAvailability::Runtime);
        $set->handle($this->environment, $this->user, 'APP_NAME', 'Shop 2', false, EnvVarAvailability::Runtime);

        $this->assertSame(['sk_live_1', true, EnvVarAvailability::Both], [$kept->fresh()->value, $kept->is_secret, $kept->fresh()->available_at]);
        $this->assertSame(
            [['APP_NAME', 'Shop 2'], ['STRIPE_SECRET', 'sk_live_1']],
            $this->environment->envVars()->orderBy('key')->get()->map(fn (EnvVar $v) => [$v->key, $v->value])->all(),
        );
        $this->assertSame(['env_var.created', 'env_var.updated', 'env_var.created', 'env_var.updated'], AuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertSame(
            ['environment_id' => $this->environment->id, 'key' => 'STRIPE_SECRET', 'is_secret' => true, 'available_at' => 'both'],
            AuditEvent::orderBy('id')->skip(1)->first()->context,
        );
        $this->assertStringNotContainsString('sk_live_1', AuditEvent::all()->toJson());
    }

    public function test_set_env_var_overrides_only_the_user_value_next_to_a_datastore_value(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'value' => 'mysql', 'source' => EnvVarSource::Datastore]);

        app(SetEnvVar::class)->handle($this->environment, $this->user, 'DB_HOST', 'my-own-db', false, EnvVarAvailability::Runtime);

        $this->assertSame(
            [['datastore', 'mysql'], ['user', 'my-own-db']],
            $this->environment->envVars()->orderBy('source')->get()->map(fn (EnvVar $v) => [$v->source->value, $v->value])->all(),
        );
    }

    public function test_set_env_var_refuses_to_reveal_a_kept_secret_and_bad_keys(): void
    {
        $set = app(SetEnvVar::class);
        $set->handle($this->environment, $this->user, 'TOKEN', 'abcd1234', true, EnvVarAvailability::Runtime);

        try {
            $set->handle($this->environment, $this->user, 'TOKEN', null, false, EnvVarAvailability::Runtime);
            $this->fail('A kept secret became plain.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Give TOKEN a new value to make it a plain value.', $e->getMessage());
        }

        $this->expectExceptionObject(new InvalidArgumentException('1BAD-KEY is not a valid env name. Use letters, digits, and underscores.'));
        $set->handle($this->environment, $this->user, '1BAD-KEY', 'x', false, EnvVarAvailability::Runtime);
    }

    public function test_delete_env_var_deletes_a_user_value_and_refuses_a_datastore_value(): void
    {
        $user = EnvVar::factory()->for($this->environment)->create(['key' => 'MAIL_HOST']);
        $datastore = EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::Datastore]);

        app(DeleteEnvVar::class)->handle($user, $this->user);

        $this->assertSame(['DB_HOST'], $this->environment->envVars()->pluck('key')->all());
        $this->assertSame(['env_var.deleted', ['key' => 'MAIL_HOST']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);

        $this->expectExceptionObject(new InvalidArgumentException('DB_HOST comes from a datastore. Detach the datastore to remove it.'));
        app(DeleteEnvVar::class)->handle($datastore, $this->user);
    }

    public function test_add_domain_normalizes_the_hostname_and_checks_dns(): void
    {
        $domain = app(AddDomain::class)->handle($this->environment, $this->user, '  WWW.Shop.Example.COM. ');

        $this->assertSame('www.shop.example.com', $domain->hostname);
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->is($domain));
        $this->assertSame(
            ['domain.added', ['environment_id' => $this->environment->id, 'hostname' => 'www.shop.example.com']],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );
    }

    public function test_remove_domain_deletes_it_and_syncs_caddy(): void
    {
        $domain = Domain::factory()->for($this->environment)->create(['hostname' => 'old.example.com']);

        app(RemoveDomain::class)->handle($domain, $this->user);

        $this->assertSame(0, Domain::count());
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $job) => $job->cluster->is($this->environment->application->cluster));
        $this->assertSame(['domain.removed', ['hostname' => 'old.example.com']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);
    }

    public function test_read_logs_reads_one_process_and_masks_secrets(): void
    {
        $kubectl = FakeKubectl::install();
        $kubectl->respond('logs', new KubectlResult(0, "[pod/web-1/web] connecting with hunter22\n"));
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);

        $logs = app(ReadLogs::class)->handle($this->environment, 'worker', 50);

        $this->assertSame("[pod/web-1/web] connecting with ********\n", $logs);
        $this->assertSame([
            'logs',
            "--namespace=oc-env-{$this->environment->id}",
            "--selector=opencloud.dev/application={$this->environment->application_id},opencloud.dev/environment={$this->environment->id},opencloud.dev/process=worker",
            '--tail=50',
            '--prefix',
            '--max-log-requests=20',
        ], $kubectl->calls[0]['args']);

        $this->expectExceptionObject(new InvalidArgumentException('deploy is not a process. Use web, worker, or scheduler.'));
        app(ReadLogs::class)->handle($this->environment, 'deploy');
    }
}
