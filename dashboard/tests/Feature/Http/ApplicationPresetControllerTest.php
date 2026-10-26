<?php

namespace Tests\Feature\Http;

use App\Datastores\AttachmentEnv;
use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarSource;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use App\Presets\EnvProfileKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicationPresetControllerTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;

    private Environment $production;

    private Environment $preview;

    private Environment $template;

    protected function setUp(): void
    {
        parent::setUp();

        $cluster = Cluster::factory()->create(['id' => 1, 'name' => 'local', 'kind' => ClusterKind::Local]);
        $this->application = Application::factory()->for($cluster)->create(['id' => 1, 'name' => 'shop', 'type' => ApplicationType::Laravel, 'env_profile' => EnvProfileKind::Laravel, 'port' => 8080]);
        $this->production = Environment::factory()->for($this->application)->create(['id' => 7, 'name' => 'production']);
        $this->preview = Environment::factory()->for($this->application)->create(['id' => 8, 'name' => 'pr-12', 'kind' => EnvironmentKind::Preview, 'pr_number' => 12]);
        $this->template = Environment::factory()->for($this->application)->create(['id' => 9, 'name' => 'preview-template', 'kind' => EnvironmentKind::PreviewTemplate, 'branch' => null]);
    }

    public function test_an_owner_switches_a_laravel_app_to_node_and_every_environment_gets_the_url_names(): void
    {
        $mysql = Datastore::factory()->for($this->application->cluster)->create(['id' => 3, 'kind' => DatastoreKind::Mysql]);
        $postgres = Datastore::factory()->for($this->application->cluster)->create(['id' => 4, 'kind' => DatastoreKind::Postgres]);
        $valkey = Datastore::factory()->for($this->application->cluster)->create(['id' => 5, 'kind' => DatastoreKind::Valkey]);
        $rustfs = Datastore::factory()->for($this->application->cluster)->create(['id' => 6, 'kind' => DatastoreKind::Rustfs]);
        $attachments = [
            $this->attachment(21, $mysql, $this->production, 'main', true, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_7', 'env_7', 'main-pass'),
            $this->attachment(22, $postgres, $this->production, 'reports', false, 'postgres-4.oc-datastores.svc.cluster.local', 5432, 'env_7_reports', 'env_7_reports', 'reports-pass'),
            $this->attachment(24, $valkey, $this->production, null, false, null, null, null, null, 'valkey-pass'),
            $this->attachment(25, $rustfs, $this->production, null, false, null, null, 'env-7', 'env-7', 'rustfs-secret'),
            $this->attachment(31, $mysql, $this->preview, 'main', true, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_8', 'env_8', 'preview-pass'),
        ];

        foreach ($attachments as $attachment) {
            AttachmentEnv::write($attachment);
        }

        $this->production->envVars()->create(['key' => 'MAIL_MAILER', 'value' => 'log', 'source' => EnvVarSource::User]);

        $this->assertSame([
            'AWS_ACCESS_KEY_ID' => ['env-7', false, 25],
            'AWS_BUCKET' => ['env-7', false, 25],
            'AWS_DEFAULT_REGION' => ['us-east-1', false, 25],
            'AWS_ENDPOINT' => ['http://rustfs-6.oc-datastores.svc.cluster.local:9000', false, 25],
            'AWS_SECRET_ACCESS_KEY' => ['rustfs-secret', true, 25],
            'AWS_USE_PATH_STYLE_ENDPOINT' => ['true', false, 25],
            'CACHE_STORE' => ['redis', false, 24],
            'DB_CONNECTION' => ['mysql', false, 21],
            'DB_DATABASE' => ['env_7', false, 21],
            'DB_HOST' => ['mysql-3.oc-datastores.svc.cluster.local', false, 21],
            'DB_PASSWORD' => ['main-pass', true, 21],
            'DB_PORT' => ['3306', false, 21],
            'DB_USERNAME' => ['env_7', false, 21],
            'FILESYSTEM_DISK' => ['s3', false, 25],
            'MAIL_MAILER' => ['log', false, null],
            'REDIS_CLIENT' => ['phpredis', false, 24],
            'REDIS_HOST' => ['valkey-24.oc-datastores.svc.cluster.local', false, 24],
            'REDIS_PASSWORD' => ['valkey-pass', true, 24],
            'REDIS_PORT' => ['6379', false, 24],
            'REPORTS_DB_URL' => ['postgres://env_7_reports:reports-pass@postgres-4.oc-datastores.svc.cluster.local:5432/env_7_reports', true, 22],
        ], $this->rows($this->production));

        $this->actingAs(User::factory()->owner()->create())
            ->from("/applications/{$this->application->id}")
            ->patch("/applications/{$this->application->id}/preset", ['type' => 'node', 'env_profile' => 'generic'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/applications/{$this->application->id}");

        $this->assertSame([
            'AWS_ACCESS_KEY_ID' => ['env-7', false, 25],
            'AWS_BUCKET' => ['env-7', false, 25],
            'AWS_ENDPOINT_URL' => ['http://rustfs-6.oc-datastores.svc.cluster.local:9000', false, 25],
            'AWS_REGION' => ['us-east-1', false, 25],
            'AWS_SECRET_ACCESS_KEY' => ['rustfs-secret', true, 25],
            'DATABASE_URL' => ['mysql://env_7:main-pass@mysql-3.oc-datastores.svc.cluster.local:3306/env_7', true, 21],
            'MAIL_MAILER' => ['log', false, null],
            'REDIS_URL' => ['redis://:valkey-pass@valkey-24.oc-datastores.svc.cluster.local:6379', true, 24],
            'REPORTS_DATABASE_URL' => ['postgresql://env_7_reports:reports-pass@postgres-4.oc-datastores.svc.cluster.local:5432/env_7_reports', true, 22],
        ], $this->rows($this->production));
        $this->assertSame(['DATABASE_URL' => ['mysql://env_8:preview-pass@mysql-3.oc-datastores.svc.cluster.local:3306/env_8', true, 31]], $this->rows($this->preview));
        $this->assertSame([], $this->rows($this->template));

        $this->application->refresh();
        $this->assertSame([ApplicationType::Node, EnvProfileKind::Generic, 8080], [$this->application->type, $this->application->env_profile, $this->application->port]);
        $this->assertSame(
            ['application.preset_updated', ['changes' => ['type' => ['from' => 'laravel', 'to' => 'node'], 'env_profile' => ['from' => 'laravel', 'to' => 'generic']]]],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );

        $this->patch("/applications/{$this->application->id}/preset", ['type' => 'node', 'env_profile' => 'generic'])->assertSessionHasNoErrors();
        $this->assertSame('application.preset_updated', AuditEvent::sole()->action);
    }

    public function test_a_preset_that_chooses_its_port_keeps_the_port_and_a_preset_with_a_fixed_port_gets_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->patch("/applications/{$this->application->id}/preset", ['type' => 'dockerfile', 'env_profile' => 'generic', 'port' => 5000])->assertSessionHasNoErrors();
        $this->assertSame([ApplicationType::Dockerfile, 5000], [$this->application->fresh()->type, $this->application->fresh()->port]);

        $this->patch("/applications/{$this->application->id}/preset", ['type' => 'node', 'env_profile' => 'generic', 'port' => null])->assertSessionHasNoErrors();
        $this->assertSame([ApplicationType::Node, 5000], [$this->application->fresh()->type, $this->application->fresh()->port]);

        $this->patch("/applications/{$this->application->id}/preset", ['type' => 'static', 'env_profile' => 'generic', 'port' => 5000])->assertSessionHasNoErrors();
        $this->assertSame([ApplicationType::Static, 8080], [$this->application->fresh()->type, $this->application->fresh()->port]);

        $this->assertSame([
            ['type' => ['from' => 'laravel', 'to' => 'dockerfile'], 'env_profile' => ['from' => 'laravel', 'to' => 'generic'], 'port' => ['from' => 8080, 'to' => 5000]],
            ['type' => ['from' => 'dockerfile', 'to' => 'node']],
            ['type' => ['from' => 'node', 'to' => 'static'], 'port' => ['from' => 5000, 'to' => 8080]],
        ], AuditEvent::query()->where('action', 'application.preset_updated')->orderBy('id')->get()->map(fn (AuditEvent $event) => $event->context['changes'])->all());
    }

    public function test_a_switch_to_laravel_gives_each_environment_without_an_app_key_its_own(): void
    {
        $this->application->update(['type' => ApplicationType::Node, 'env_profile' => EnvProfileKind::Generic, 'port' => 3000]);
        $this->production->envVars()->create(['key' => 'APP_KEY', 'value' => 'base64:kept', 'is_secret' => true, 'source' => EnvVarSource::User]);

        $this->actingAs(User::factory()->owner()->create())
            ->patch("/applications/{$this->application->id}/preset", ['type' => 'laravel', 'env_profile' => 'laravel'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['APP_KEY' => ['base64:kept', true, null]], $this->rows($this->production));
        $this->assertSame([], $this->rows($this->template));

        $key = EnvVar::query()->where('environment_id', $this->preview->id)->sole();
        $this->assertSame(['APP_KEY', true, EnvVarSource::User], [$key->key, $key->is_secret, $key->source]);
        $this->assertSame(32, strlen(base64_decode(substr($key->value, strlen('base64:')), true)));

        $this->assertSame(8080, $this->application->fresh()->port);
        $this->assertSame([
            ['env_var.created', ['environment_id' => 8, 'key' => 'APP_KEY', 'is_secret' => true, 'available_at' => 'runtime']],
            ['application.preset_updated', ['changes' => ['type' => ['from' => 'node', 'to' => 'laravel'], 'env_profile' => ['from' => 'generic', 'to' => 'laravel'], 'port' => ['from' => 3000, 'to' => 8080]]]],
        ], AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->context])->all());
    }

    public function test_a_developer_cannot_change_the_preset_and_bad_values_are_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch("/applications/{$this->application->id}/preset", ['type' => 'node', 'env_profile' => 'generic'])
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/preset", ['type' => 'rails', 'port' => 70000])
            ->assertSessionHasErrors([
                'type' => 'The selected type is invalid.',
                'env_profile' => 'The env profile field is required.',
                'port' => 'The port field must be between 1 and 65535.',
            ]);

        $this->application->refresh();
        $this->assertSame([ApplicationType::Laravel, EnvProfileKind::Laravel, 8080], [$this->application->type, $this->application->env_profile, $this->application->port]);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_the_application_page_shows_the_preset_the_env_names_and_the_presets_to_pick(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get("/applications/{$this->application->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->where('application', ['id' => 1, 'name' => 'shop', 'repository' => $this->application->repository, 'type' => 'laravel', 'env_profile' => 'laravel', 'port' => 8080])
                ->has('presets', 4)
                ->where('presets.1', [
                    'value' => 'node',
                    'port' => 3000,
                    'chooses_port' => true,
                    'uses_build_command' => true,
                    'deploy_commands' => [],
                    'processes' => [],
                    'database' => 'none',
                    'env_profile' => 'generic',
                ])
                ->where('canUpdate', true));
    }

    private function attachment(int $id, Datastore $datastore, Environment $environment, ?string $name, bool $isDefault, ?string $host, ?int $port, ?string $database, ?string $username, string $password): DatastoreAttachment
    {
        return DatastoreAttachment::factory()->create([
            'id' => $id,
            'datastore_id' => $datastore->id,
            'environment_id' => $environment->id,
            'name' => $name,
            'is_default' => $isDefault,
            'host' => $host,
            'port' => $port,
            'database_name' => $database,
            'username' => $username,
            'password' => $password,
        ]);
    }

    /** @return array<string, array{0: string, 1: bool, 2: ?int}> */
    private function rows(Environment $environment): array
    {
        return EnvVar::query()->where('environment_id', $environment->id)->orderBy('key')->get()
            ->mapWithKeys(fn (EnvVar $var) => [$var->key => [$var->value, $var->is_secret, $var->datastore_attachment_id]])
            ->all();
    }
}
