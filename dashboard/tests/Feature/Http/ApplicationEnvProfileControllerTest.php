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

class ApplicationEnvProfileControllerTest extends TestCase
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

    public function test_an_owner_changes_the_env_names_and_every_environment_gets_the_url_names(): void
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
            ->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'generic'])
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
        $this->assertSame([ApplicationType::Laravel, EnvProfileKind::Generic, 8080], [$this->application->type, $this->application->env_profile, $this->application->port]);
        $this->assertSame(
            ['application.env_profile_updated', ['from' => 'laravel', 'to' => 'generic']],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );

        $this->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'generic'])->assertSessionHasNoErrors();
        $this->assertSame('application.env_profile_updated', AuditEvent::sole()->action);
    }

    public function test_a_save_with_the_same_env_names_changes_nothing(): void
    {
        $mysql = Datastore::factory()->for($this->application->cluster)->create(['id' => 3, 'kind' => DatastoreKind::Mysql]);
        $attachment = $this->attachment(21, $mysql, $this->production, 'main', true, 'mysql-3.oc-datastores.svc.cluster.local', 3306, 'env_7', 'env_7', 'main-pass');
        AttachmentEnv::write($attachment);
        $this->production->envVars()->where('key', 'DB_HOST')->sole()->update(['value' => 'edited-by-hand']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'laravel'])
            ->assertSessionHasNoErrors();

        $this->assertSame('edited-by-hand', $this->rows($this->production)['DB_HOST'][0]);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_a_request_that_sends_the_type_or_the_port_changes_neither(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'generic', 'type' => 'node', 'port' => 3000])
            ->assertSessionHasNoErrors();

        $this->application->refresh();
        $this->assertSame([ApplicationType::Laravel, EnvProfileKind::Generic, 8080], [$this->application->type, $this->application->env_profile, $this->application->port]);
        $this->assertSame(['from' => 'laravel', 'to' => 'generic'], AuditEvent::sole()->context);

        $this->patch("/applications/{$this->application->id}/env-profile", ['type' => 'node', 'port' => 3000])
            ->assertSessionHasErrors(['env_profile' => 'The env profile field is required.']);

        $this->application->refresh();
        $this->assertSame([ApplicationType::Laravel, 8080], [$this->application->type, $this->application->port]);
        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_an_admin_changes_the_env_names_and_a_developer_cannot(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'generic'])
            ->assertForbidden();

        $this->assertSame(EnvProfileKind::Laravel, $this->application->fresh()->env_profile);
        $this->assertSame(0, AuditEvent::query()->count());

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'generic'])
            ->assertSessionHasNoErrors();

        $this->assertSame(EnvProfileKind::Generic, $this->application->fresh()->env_profile);
    }

    public function test_a_bad_env_profile_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/env-profile", ['env_profile' => 'rails'])
            ->assertSessionHasErrors(['env_profile' => 'The selected env profile is invalid.']);

        $this->assertSame(EnvProfileKind::Laravel, $this->application->fresh()->env_profile);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_the_old_preset_route_is_gone(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch("/applications/{$this->application->id}/preset", ['type' => 'node', 'env_profile' => 'generic'])
            ->assertNotFound();

        $this->assertSame(ApplicationType::Laravel, $this->application->fresh()->type);
    }

    public function test_the_application_page_gets_the_preset_and_the_env_names(): void
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
