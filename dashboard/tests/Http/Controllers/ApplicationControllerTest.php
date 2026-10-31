<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsEnvironments;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class ApplicationControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validForm(array $overrides = []): array
    {
        return [
            'repository' => 'acme/shop',
            'branch' => 'main',
            'cluster_id' => $this->makeCluster()->id,
            'type' => 'laravel',
            ...$overrides,
        ];
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $environment = $this->makeEnvironment();

        foreach (['/applications', '/applications/create', '/applications/1', "/environments/{$environment->id}", '/clusters', '/settings/audit'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $this->post('/applications', [])->assertRedirect('/login');
    }

    public function test_the_dashboard_redirects_to_the_applications_list(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/applications');
    }

    public function test_the_list_shows_the_status_domain_and_last_deploy_of_production(): void
    {
        $production = $this->makeEnvironment();
        Deployment::factory()->for($production)->create(['status' => DeploymentStatus::SUPERSEDED]);
        $latest = Deployment::factory()->for($production)->create(['status' => DeploymentStatus::BUILDING]);
        Domain::factory()->for($production)->create(['hostname' => 'shop.example.com']);
        Domain::factory()->for($production)->create(['hostname' => 'www.example.com']);
        Application::factory()->for($this->cluster)->create(['name' => 'blog']);

        $this->actingAs(User::factory()->owner()->create())->get('/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/index')
                ->where('canCreate', true)
                ->has('applications', 2)
                ->where('applications.0.name', 'blog')
                ->where('applications.0.status', null)
                ->where('applications.0.url', null)
                ->where('applications.1.name', 'shop')
                ->where('applications.1.production_id', $production->id)
                ->where('applications.1.status', 'building')
                ->where('applications.1.url', 'https://shop.example.com')
                ->where('applications.1.deployed_at', $latest->created_at->toIso8601String()));
    }

    public function test_a_developer_sees_the_list_without_the_create_right(): void
    {
        $this->actingAs(User::factory()->create())->get('/applications')
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', false)->where('can.create_application', false)->where('can.view_audit_log', false));
    }

    public function test_the_create_form_offers_only_the_clusters(): void
    {
        $this->makeCluster();

        $this->actingAs(User::factory()->admin()->create())->get('/applications/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/create')
                ->where('defaultClusterId', $this->cluster->id)
                ->where('clusters.0.ingress_ip', '203.0.113.10')
                ->missing('sizes')
                ->missing('presets'));
    }

    public function test_a_developer_cannot_open_or_submit_the_create_form(): void
    {
        $developer = User::factory()->create();

        $this->actingAs($developer)->get('/applications/create')->assertForbidden();
        $this->actingAs($developer)->post('/applications', $this->validForm())->assertForbidden();
        $this->assertSame(0, Application::count());
    }

    public function test_it_calls_create_application_with_a_normalized_repository_and_the_five_form_values(): void
    {
        $admin = User::factory()->admin()->create();
        $created = Application::factory()->for($this->makeCluster())->create();
        $production = Environment::factory()->for($created)->create(['name' => 'production', 'is_protected' => true]);

        $this->mock(CreateApplication::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn (User $by, array $data) => $by->is($admin) && $data === [
                'name' => 'shop',
                'repository' => 'https://github.com/acme/shop.git',
                'cluster_id' => $this->cluster->id,
                'type' => ApplicationType::LARAVEL,
                'port' => null,
                'branch' => 'main',
                'deploy_key' => false,
                'deploy_private_key' => null,
            ])
            ->andReturn($created);

        $this->actingAs($admin)->post('/applications', $this->validForm())->assertRedirect("/environments/{$production->id}");
    }

    public function test_a_new_deploy_key_creates_the_application_without_a_remote_call_and_opens_the_production_environment_page(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->post('/applications', $this->validForm(['branch' => '', 'deploy_key' => true]));

        $application = Application::sole();
        $response->assertRedirect("/environments/{$application->environments()->where('name', 'production')->sole()->id}");
        $this->assertSame([FakeProcesses::PUBLIC_KEY.' opencloud shop', ['ssh-keygen']], [$application->deploy_public_key, array_unique(array_column($processes->commands(), 0))]);
    }

    public function test_the_create_form_refuses_a_deploy_key_for_a_repository_outside_github(): void
    {
        $processes = FakeProcesses::install();
        $owner = User::factory()->owner()->create();

        foreach ([['deploy_key' => true], ['deploy_private_key' => FakeProcesses::PRIVATE_KEY]] as $key) {
            $this->actingAs($owner)
                ->post('/applications', $this->validForm(['repository' => 'https://gitlab.com/acme/shop.git', ...$key]))
                ->assertSessionHasErrors([array_key_first($key) => 'A deploy key works only for a repository on github.com.']);
        }

        $this->assertSame([0, []], [Application::count(), $processes->commands()]);
    }

    public function test_an_own_key_reaches_create_application_and_opens_the_environment_page(): void
    {
        $production = $this->makeEnvironment();

        $this->mock(CreateApplication::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn (User $by, array $data) => [$data['deploy_key'], $data['deploy_private_key']] === [false, trim(FakeProcesses::PRIVATE_KEY)])
            ->andReturn($production->application);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/applications', $this->validForm(['deploy_private_key' => FakeProcesses::PRIVATE_KEY]))
            ->assertRedirect("/environments/{$production->id}");
    }

    public function test_the_create_form_refuses_a_new_key_and_an_own_key_together(): void
    {
        $this->mock(CreateApplication::class)->shouldNotReceive('execute');

        $this->actingAs(User::factory()->admin()->create())
            ->post('/applications', $this->validForm(['deploy_key' => true, 'deploy_private_key' => FakeProcesses::PRIVATE_KEY]))
            ->assertSessionHasErrors(['deploy_private_key' => 'Choose a new key or your own key. Do not choose both.']);
    }

    /** A bad key and a refused key both fail in CreateApplication. Each path keeps the other input and drops the key. */
    public function test_a_bad_or_refused_own_key_comes_back_on_the_key_field_creates_nothing_and_never_reaches_the_session(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $this->actingAs(User::factory()->owner()->create())->from('/applications/create');

        $this->post('/applications', $this->validForm(['deploy_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nsecret-key-text\n-----END OPENSSH PRIVATE KEY-----"]))
            ->assertRedirect('/applications/create')
            ->assertSessionHasErrors(['deploy_private_key' => 'Paste an OpenSSH private key without a passphrase.']);
        $this->assertSame(['acme/shop', null], [session()->getOldInput('repository'), session()->getOldInput('deploy_private_key')]);

        $processes->lsRemoteError = "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n";
        $this->post('/applications', $this->validForm(['deploy_private_key' => FakeProcesses::PRIVATE_KEY]))
            ->assertRedirect('/applications/create')
            ->assertSessionHasErrors(['deploy_private_key' => 'GitHub refused the deploy key. Add the public key to the repository under Settings, Deploy keys.']);
        $this->assertSame(['acme/shop', null], [session()->getOldInput('repository'), session()->getOldInput('deploy_private_key')]);

        $this->assertSame([0, 0], [Application::count(), AuditEvent::count()]);
        Queue::assertNothingPushed();
    }

    public function test_it_creates_an_application_end_to_end_from_a_repository_alone_and_starts_no_deployment(): void
    {
        Queue::fake();
        FakeProcesses::install();
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->post('/applications', [
            'repository' => 'https://github.com/acme/shop.git',
            'cluster_id' => $this->makeCluster()->id,
            'type' => 'dockerfile',
            'port' => 3000,
        ]);

        $application = Application::sole();
        $production = $application->environments()->where('name', 'production')->sole();
        $response->assertRedirect("/environments/{$production->id}");
        $this->assertSame(['shop', ApplicationType::DOCKERFILE, 3000, EnvironmentKind::PERMANENT], [$application->name, $application->type, $application->port, $production->kind]);
        $this->assertSame(['s', 1, 1, 70, []], [$production->size->value, $production->min_instances, $production->max_instances, $production->cpu_target, $production->processes->all()]);
        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNotPushed(RunDeployment::class);
    }

    /**
     * What the form posts after a click on each preset, and what opencloud stores: the type, the port, the deploy
     * commands, the health path, the processes, the user env keys, and the databases. The preset decides all but the type and the port.
     *
     * @return array<string, array{array<string, mixed>, list<mixed>}>
     */
    public static function presetForms(): array
    {
        return [
            'laravel' => [
                ['type' => 'laravel', 'port' => null],
                ['laravel', 8080, ['php artisan migrate --force'], '/up', ['worker', 'scheduler'], ['APP_KEY'], ['mysql']],
            ],
            'node with its own port' => [
                ['type' => 'node', 'port' => 4000],
                ['node', 4000, [], '/', [], [], []],
            ],
            'python' => [
                ['type' => 'python', 'port' => 8000],
                ['python', 8000, ['if [ -f manage.py ]; then python manage.py migrate --noinput; fi'], '/', [], ['SECRET_KEY'], []],
            ],
            'ruby' => [
                ['type' => 'ruby', 'port' => 9292],
                ['ruby', 9292, ['if [ -f bin/rails ]; then bin/rails db:prepare; fi'], '/', [], ['SECRET_KEY_BASE'], []],
            ],
            'static' => [
                ['type' => 'static', 'port' => null],
                ['static', 8080, [], '/', [], [], []],
            ],
            'dockerfile' => [
                ['type' => 'dockerfile', 'port' => 3000],
                ['dockerfile', 3000, [], '/', [], [], []],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  list<mixed>  $expected
     */
    #[DataProvider('presetForms')]
    public function test_the_form_creates_each_preset_with_its_defaults(array $form, array $expected): void
    {
        Queue::fake();
        FakeProcesses::install()->symref .= self::SHA."\trefs/heads/main\n";

        $this->actingAs(User::factory()->owner()->create())
            ->post('/applications', $this->validForm($form))
            ->assertRedirect();

        $application = Application::sole();
        $production = $application->environments()->where('name', 'production')->sole();
        $this->assertSame($expected, [
            $application->type->value,
            $application->port,
            $production->deploy_commands,
            $production->health_path,
            $production->processes()->pluck('name')->all(),
            $production->envVars()->where('source', 'user')->orderBy('key')->pluck('key')->all(),
            $production->datastoreAttachments()->with('datastore')->get()->map(fn ($attachment) => $attachment->datastore->kind->value)->all(),
        ]);
    }

    public function test_the_create_form_takes_no_env_names_and_the_preset_names_the_database_values(): void
    {
        Queue::fake();
        FakeProcesses::install()->symref .= self::SHA."\trefs/heads/main\n";

        $this->actingAs(User::factory()->owner()->create())
            ->post('/applications', $this->validForm(['env_profile' => 'django']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $application = Application::sole();
        $this->assertArrayNotHasKey('env_profile', $application->getAttributes());
        $this->assertSame(
            ['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'],
            $application->environments()->where('name', 'production')->sole()->envVars()->where('source', 'datastore')->orderBy('key')->pluck('key')->all(),
        );
    }

    public function test_the_routes_that_changed_the_preset_and_the_env_names_are_gone(): void
    {
        $application = $this->makeEnvironment()->application;

        foreach (['preset' => ['type' => 'node'], 'env-profile' => ['env_profile' => 'generic']] as $path => $body) {
            $this->actingAs(User::factory()->owner()->create())
                ->patch("/applications/{$application->id}/{$path}", $body)
                ->assertNotFound();
        }

        $this->assertSame(ApplicationType::LARAVEL, $application->fresh()->type);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_the_application_page_shows_the_preset_and_no_env_names(): void
    {
        $application = $this->makeEnvironment()->application;

        $this->actingAs(User::factory()->admin()->create())->get("/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->where('application.type', 'laravel')
                ->missing('application.env_profile')
                ->missing('presets')
                ->where('canUpdate', true));
    }

    public function test_a_repository_that_git_cannot_read_comes_back_with_the_summary_on_the_repository_field(): void
    {
        FakeProcesses::install()->lsRemoteError = "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n";

        $this->actingAs(User::factory()->owner()->create())->from('/applications/create')
            ->post('/applications', $this->validForm(['repository' => 'acme/missing']))
            ->assertRedirect('/applications/create')
            ->assertSessionHasErrors(['repository' => 'Opencloud cannot read acme/missing. It is private, or the name is wrong.']);

        $this->assertSame(0, Application::count());
    }

    public function test_the_form_is_validated(): void
    {
        $owner = User::factory()->owner()->create();
        $this->mock(CreateApplication::class)->shouldNotReceive('execute');

        $cases = [
            'repository' => [['repository' => ''], 'repository'],
            'a repository that is not owner/repo, a github.com SSH address, or https' => [['repository' => 'git@gitlab.com:acme/shop.git'], 'repository'],
            'an unknown cluster' => [['cluster_id' => 999], 'cluster_id'],
            'an unknown preset' => [['type' => 'rails'], 'type'],
            'a port above 65535' => [['type' => 'dockerfile', 'port' => 65536], 'port'],
        ];

        foreach ($cases as [$overrides, $field]) {
            $this->actingAs($owner)->post('/applications', $this->validForm($overrides))->assertSessionHasErrors($field);
        }
    }

    public function test_the_application_page_lists_production_first_then_permanent_then_the_template_then_previews(): void
    {
        $production = $this->makeEnvironment(name: 'production');
        $application = $production->application;
        Environment::factory()->for($application)->create(['name' => 'pr-7', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 7]);
        Environment::factory()->for($application)->create(['name' => 'staging', 'kind' => EnvironmentKind::PERMANENT]);
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        Deployment::factory()->for($production)->create(['status' => DeploymentStatus::LIVE]);
        Domain::factory()->for($production)->create(['hostname' => 'shop.example.com']);

        $this->actingAs(User::factory()->create())->get("/applications/{$application->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->where('application.name', 'shop')
                ->has('environments', 4)
                ->where('environments.0.name', 'production')
                ->where('environments.0.status', 'live')
                ->where('environments.0.url', 'https://shop.example.com')
                ->where('environments.1.name', 'staging')
                ->where('environments.2.name', 'preview-template')
                ->where('environments.2.kind', 'preview_template')
                ->where('environments.3.name', 'pr-7')
                ->where('environments.3.pr_number', 7)
                ->has('previews', fn (Assert $previews) => $previews
                    ->where('previews_enabled', true)
                    ->where('max_previews', 5)
                    ->missing('preview_domain')
                    ->missing('allow_forks')));
    }

    public function test_the_application_page_shows_no_deploy_key(): void
    {
        $application = $this->makeEnvironment()->application;
        $application->update(['deploy_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nsecret-key-text\n-----END OPENSSH PRIVATE KEY-----\n", 'deploy_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKey opencloud shop']);

        $this->actingAs(User::factory()->create())->get("/applications/{$application->id}")
            ->assertOk()
            ->assertDontSee('secret-key-text')
            ->assertDontSee('AAAAC3NzaC1lZDI1NTE5AAAAIKey')
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->missing('deployPublicKey')
                ->where('canUpdate', false));
    }
}
