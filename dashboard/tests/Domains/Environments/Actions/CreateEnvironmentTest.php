<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\CreateEnvironment;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class CreateEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private const STAGING_SHA = '5555555555555555555555555555555555555555';

    private FakeProcesses $processes;

    private User $admin;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunDeployment::class, ProvisionDatastoreAttachment::class, CheckDomain::class]);
        FakeKubectl::install();
        $this->processes = FakeProcesses::install();
        $this->processes->lsRemote = self::STAGING_SHA."\trefs/heads/main\n";
        $this->admin = User::factory()->admin()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);

        $this->application = app(CreateApplication::class)->execute($this->admin, [
            'name' => 'Shop',
            'repository' => 'acme/shop',
            'cluster_id' => $cluster->id,
        ]);
        $production = $this->environment('production');
        $production->update(['size' => Size::L, 'min_instances' => 2, 'max_instances' => 4, 'cpu_target' => 60]);
        $setEnvVar = app(SetEnvVar::class);
        $setEnvVar->execute($production, $this->admin, 'STRIPE_SECRET', 'sk_live_production', true, EnvVarAvailability::RUNTIME);
        $setEnvVar->execute($production, $this->admin, 'MAIL_MAILER', 'smtp', false, EnvVarAvailability::RUNTIME);
        $setEnvVar->execute($production, $this->admin, 'DB_HOST', 'production-db.internal', false, EnvVarAvailability::RUNTIME);
        app(AttachDatastore::class)->execute($production, $this->admin, DatastoreKind::VALKEY);
        $managed = Datastore::factory()->create(['cluster_id' => $cluster->id, 'kind' => DatastoreKind::POSTGRES, 'source' => DatastoreSource::MANAGED]);
        DatastoreAttachment::factory()->create(['datastore_id' => $managed->id, 'environment_id' => $production->id, 'name' => 'reports', 'is_default' => false]);

        $template = $this->environment('preview-template');
        $template->update(['health_path' => '/health', 'deploy_commands' => ['php artisan migrate:fresh --seed --force'], 'min_instances' => 2, 'max_instances' => 3]);
        $template->processes()->where('name', 'worker')->delete();
        Process::factory()->for($template)->create(['name' => 'horizon', 'command' => 'php artisan horizon', 'size' => Size::M, 'min_instances' => 1, 'max_instances' => 3, 'cpu_target' => 60]);
        $setEnvVar->execute($template, $this->admin, 'MAIL_MAILER', 'log', false, EnvVarAvailability::RUNTIME);
        $setEnvVar->execute($template, $this->admin, 'STRIPE_SECRET', 'sk_test_preview', true, EnvVarAvailability::RUNTIME);

        AuditEvent::query()->delete();
    }

    public function test_a_copy_of_production_takes_its_settings_processes_and_plain_env_values_with_new_resources_of_its_own(): void
    {
        $production = $this->environment('production');
        $productionAttachments = $this->attachmentRows($production);

        $staging = app(CreateEnvironment::class)->execute($this->application, $this->admin, 'staging', 'staging', $production);

        $this->assertSame(
            [EnvironmentKind::PERMANENT, 'staging', false, true, null, 'l', 2, 4, 60, '/up', ['php artisan migrate --force']],
            [$staging->kind, $staging->branch, $staging->is_protected, $staging->auto_deploy, $staging->pr_number, $staging->size->value, $staging->min_instances, $staging->max_instances, $staging->cpu_target, $staging->health_path, $staging->deploy_commands],
        );
        $this->assertSame(
            [['worker', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600'], ['scheduler', 'php artisan schedule:work']],
            $staging->processes->map(fn (Process $p) => [$p->name, $p->command])->all(),
        );

        // A developer can read the values of staging, so the secrets of production stay there. APP_KEY is new.
        $env = $this->userEnv($staging);
        $this->assertSame(['APP_KEY', 'MAIL_MAILER'], array_column($env, 0));
        $this->assertSame(['MAIL_MAILER', 'smtp', false, 'runtime'], $env[1]);
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $env[0][1]);
        $this->assertNotSame($production->envVars()->where('key', 'APP_KEY')->sole()->value, $env[0][1]);
        $this->assertSame(0, EnvVar::query()->where('environment_id', $staging->id)->where('value', 'sk_live_production')->count());
        // A user value wins over a resource value, so the DB_HOST of production would point staging at the data of production.
        $this->assertSame(EnvVarSource::DATASTORE, $staging->envVars()->where('key', 'DB_HOST')->sole()->source);

        // Each resource is a new standard one with its own database and user, so production keeps its data.
        $this->assertSame(
            [['mysql', 'main', true, "env_{$staging->id}"], ['valkey', null, false, null], ['postgres', 'reports', false, "env_{$staging->id}_reports"]],
            $staging->datastoreAttachments()->with('datastore')->orderBy('id')->get()
                ->map(fn (DatastoreAttachment $a) => [$a->datastore->kind->value, $a->name, $a->is_default, $a->database_name])->all(),
        );
        $this->assertSame([DatastoreSource::STANDARD], $staging->datastoreAttachments()->with('datastore')->get()->pluck('datastore.source')->unique()->values()->all());
        $this->assertSame("env_{$staging->id}", $staging->envVars()->where('key', 'DB_DATABASE')->sole()->value);
        $this->assertSame($productionAttachments, $this->attachmentRows($production));

        $this->assertSame([['shop-staging.203.0.113.10.sslip.io', true]], $staging->domains()->get(['hostname', 'generated'])->map(fn ($d) => [$d->hostname, $d->generated])->all());
        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNotPushed(RunDeployment::class);
        $this->assertContains(['git', 'ls-remote', 'https://github.com/acme/shop.git', 'refs/heads/staging'], $this->processes->commands());

        $this->assertSame(
            ['environment.created', 'datastore.attached', 'datastore.attached', 'datastore.attached', 'environment.env_copied', 'domain.added'],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(
            ['branch' => 'staging', 'copied_from' => 'production', 'processes' => ['worker', 'scheduler']],
            AuditEvent::where('action', 'environment.created')->sole()->context,
        );
        $this->assertSame(
            ['keys' => ['MAIL_MAILER'], 'left_out' => ['APP_KEY', 'DB_HOST', 'STRIPE_SECRET'], 'generated' => ['APP_KEY']],
            AuditEvent::where('action', 'environment.env_copied')->sole()->context,
        );
        $this->assertSame([$this->admin->id], AuditEvent::query()->distinct()->pluck('user_id')->all());
        $this->assertStringNotContainsString('sk_live_production', AuditEvent::all()->toJson());
    }

    public function test_a_copy_of_the_preview_template_takes_the_preview_settings_one_web_instance_and_a_new_app_key(): void
    {
        $staging = app(CreateEnvironment::class)->execute($this->application, $this->admin, 'qa', 'release/2.0', $this->environment('preview-template'));

        $this->assertSame(
            [EnvironmentKind::PERMANENT, 'release/2.0', 's', 1, 1, '/health', ['php artisan migrate:fresh --seed --force']],
            [$staging->kind, $staging->branch, $staging->size->value, $staging->min_instances, $staging->max_instances, $staging->health_path, $staging->deploy_commands],
        );
        $this->assertSame(['scheduler', 'horizon'], $staging->processes->pluck('name')->all());

        $env = $this->userEnv($staging);
        $this->assertSame(['APP_KEY', 'MAIL_MAILER', 'STRIPE_SECRET'], array_column($env, 0));
        $this->assertSame(['STRIPE_SECRET', 'sk_test_preview', true, 'runtime'], $env[2]);
        $this->assertNotSame($this->environment('production')->envVars()->where('key', 'APP_KEY')->sole()->value, $env[0][1]);

        $this->assertSame(['mysql', 'valkey', 'postgres'], $staging->datastoreAttachments()->with('datastore')->orderBy('id')->get()->pluck('datastore.kind.value')->all());
        $this->assertSame(['shop-qa.203.0.113.10.sslip.io'], $staging->domains()->pluck('hostname')->all());
        $this->assertSame(
            ['keys' => ['MAIL_MAILER', 'STRIPE_SECRET'], 'left_out' => [], 'generated' => ['APP_KEY']],
            AuditEvent::where('action', 'environment.env_copied')->sole()->context,
        );
    }

    public function test_a_branch_that_does_not_exist_creates_nothing(): void
    {
        $this->processes->lsRemote = '';
        $before = [Environment::count(), Deployment::count()];

        try {
            app(CreateEnvironment::class)->execute($this->application, $this->admin, 'staging', 'staging', $this->environment('production'));
            $this->fail('The missing branch was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['branch' => ['The branch staging does not exist in https://github.com/acme/shop.git.']], $e->errors());
        }

        $this->assertSame($before, [Environment::count(), Deployment::count()]);
        $this->assertSame(0, AuditEvent::count());
    }

    private function environment(string $name): Environment
    {
        return $this->application->environments()->where('name', $name)->sole();
    }

    /** @return list<array{string, string, bool, string}> */
    private function userEnv(Environment $environment): array
    {
        return $environment->envVars()->where('source', EnvVarSource::USER)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => [$var->key, $var->value, $var->is_secret, $var->available_at->value])->all();
    }

    /** @return list<array<string, mixed>> */
    private function attachmentRows(Environment $environment): array
    {
        return $environment->datastoreAttachments()->orderBy('id')->get(['id', 'datastore_id', 'name', 'database_name', 'username'])->toArray();
    }
}
