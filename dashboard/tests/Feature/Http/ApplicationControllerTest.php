<?php

namespace Tests\Feature\Http;

use App\Actions\CreateApplication;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\User;
use App\Presets\EnvProfileKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'env_profile' => 'laravel',
            'size' => 's',
            'min_instances' => 1,
            'max_instances' => 3,
            'cpu_target' => 70,
            'deploy_commands' => ['php artisan migrate --force'],
            'processes' => [['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 2, 'exclusive' => false]],
            'env' => [],
            'database' => 'mysql',
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
        Deployment::factory()->for($production)->create(['status' => DeploymentStatus::Superseded]);
        $latest = Deployment::factory()->for($production)->create(['status' => DeploymentStatus::Building]);
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

    public function test_the_create_form_offers_the_clusters_and_the_sizes(): void
    {
        $this->makeCluster();

        $this->actingAs(User::factory()->admin()->create())->get('/applications/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/create')
                ->where('defaultClusterId', $this->cluster->id)
                ->where('clusters.0.ingress_ip', '203.0.113.10')
                ->where('sizes.1', ['value' => 's', 'cpu_millicores' => 500, 'memory_mib' => 512])
                ->has('sizes', 4)
                ->where('presets', [
                    [
                        'value' => 'laravel',
                        'port' => 8080,
                        'chooses_port' => false,
                        'uses_build_command' => true,
                        'deploy_commands' => ['php artisan migrate --force'],
                        'processes' => [
                            ['name' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'instances' => 1, 'exclusive' => false],
                            ['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true],
                        ],
                        'database' => 'mysql',
                        'env_profile' => 'laravel',
                    ],
                    [
                        'value' => 'dockerfile',
                        'port' => 8080,
                        'chooses_port' => true,
                        'uses_build_command' => false,
                        'deploy_commands' => [],
                        'processes' => [],
                        'database' => 'none',
                        'env_profile' => 'generic',
                    ],
                ]));
    }

    public function test_a_developer_cannot_open_or_submit_the_create_form(): void
    {
        $developer = User::factory()->create();

        $this->actingAs($developer)->get('/applications/create')->assertForbidden();
        $this->actingAs($developer)->post('/applications', $this->validForm())->assertForbidden();
        $this->assertSame(0, Application::count());
    }

    public function test_it_calls_create_application_with_a_normalized_repository_and_the_form_values(): void
    {
        $admin = User::factory()->admin()->create();
        $created = Application::factory()->for($this->makeCluster())->create();
        $production = Environment::factory()->for($created)->create(['name' => 'production', 'is_protected' => true]);

        $this->mock(CreateApplication::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn (User $by, array $data) => $by->is($admin) && $data === [
                'name' => 'shop',
                'repository' => 'https://github.com/acme/shop.git',
                'cluster_id' => $this->cluster->id,
                'type' => ApplicationType::Laravel,
                'env_profile' => EnvProfileKind::Laravel,
                'port' => null,
                'branch' => 'main',
                'env' => [
                    ['key' => 'APP_ENV', 'value' => 'production', 'is_secret' => false, 'available_at' => 'runtime'],
                    ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_1', 'is_secret' => true, 'available_at' => 'runtime'],
                ],
                'domains' => ['shop.example.com'],
                'database' => 'postgres',
                'processes' => [['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 2, 'exclusive' => false]],
                'settings' => [
                    'size' => 's',
                    'min_instances' => 1,
                    'max_instances' => 3,
                    'cpu_target' => 70,
                    'build_command' => null,
                    'deploy_commands' => ['php artisan migrate --force'],
                ],
            ])
            ->andReturn($created);

        $this->actingAs($admin)->post('/applications', $this->validForm([
            'env' => [
                ['key' => 'APP_ENV', 'value' => 'production', 'is_secret' => false],
                ['key' => 'STRIPE_SECRET', 'value' => 'sk_live_1', 'is_secret' => true],
            ],
            'domain' => 'Shop.Example.com',
            'database' => 'postgres',
        ]))->assertRedirect("/environments/{$production->id}");
    }

    public function test_it_creates_an_application_end_to_end_from_a_repository_alone(): void
    {
        Queue::fake();
        $processes = FakeProcesses::install();
        $processes->lsRemote = self::SHA."\trefs/heads/main\n";
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->post('/applications', [
            'repository' => 'https://github.com/acme/shop.git',
            'cluster_id' => $this->makeCluster()->id,
            'type' => 'dockerfile',
            'env_profile' => 'generic',
            'port' => 3000,
            'size' => 'm',
            'min_instances' => 2,
            'max_instances' => 4,
            'cpu_target' => 60,
            'database' => 'none',
        ]);

        $application = Application::sole();
        $production = $application->environments()->where('name', 'production')->sole();
        $response->assertRedirect("/environments/{$production->id}");
        $this->assertSame(['shop', ApplicationType::Dockerfile, 3000, EnvironmentKind::Permanent], [$application->name, $application->type, $application->port, $production->kind]);
        $this->assertSame(['m', 2, 4, 60, []], [$production->size->value, $production->min_instances, $production->max_instances, $production->cpu_target, $production->processes->all()]);
        $this->assertSame(0, AuditEvent::where('action', 'environment.updated')->count());
    }

    /**
     * What the form posts after a click on each preset, and what opencloud stores: the type, the env profile, the
     * port, the deploy commands, the health path, the processes, the user env keys, and the databases.
     *
     * @return array<string, array{array<string, mixed>, list<mixed>}>
     */
    public static function presetForms(): array
    {
        $laravelProcesses = [
            ['name' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'instances' => 1, 'exclusive' => false],
            ['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true],
        ];

        return [
            'laravel' => [
                ['type' => 'laravel', 'env_profile' => 'laravel', 'port' => null, 'deploy_commands' => ['php artisan migrate --force'], 'processes' => $laravelProcesses, 'database' => 'mysql'],
                ['laravel', 'laravel', 8080, ['php artisan migrate --force'], '/up', ['worker', 'scheduler'], ['APP_KEY'], ['mysql']],
            ],
            'dockerfile' => [
                ['type' => 'dockerfile', 'env_profile' => 'generic', 'port' => 3000, 'deploy_commands' => [''], 'processes' => [], 'database' => 'none'],
                ['dockerfile', 'generic', 3000, [], '/', [], [], []],
            ],
            'dockerfile with the laravel names and postgres' => [
                ['type' => 'dockerfile', 'env_profile' => 'laravel', 'port' => 8080, 'deploy_commands' => ['php /var/www/deploy.php'], 'processes' => [], 'database' => 'postgres'],
                ['dockerfile', 'laravel', 8080, ['php /var/www/deploy.php'], '/', [], [], ['postgres']],
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
        FakeProcesses::install()->lsRemote = self::SHA."\trefs/heads/main\n";

        $this->actingAs(User::factory()->owner()->create())
            ->post('/applications', $this->validForm(['env' => [], ...$form]))
            ->assertRedirect();

        $application = Application::sole();
        $production = $application->environments()->where('name', 'production')->sole();
        $this->assertSame($expected, [
            $application->type->value,
            $application->env_profile->value,
            $application->port,
            $production->deploy_commands,
            $production->health_path,
            $production->processes()->pluck('name')->all(),
            $production->envVars()->where('source', 'user')->orderBy('key')->pluck('key')->all(),
            $production->datastoreAttachments()->with('datastore')->get()->map(fn ($attachment) => $attachment->datastore->kind->value)->all(),
        ]);
    }

    public function test_a_repository_that_git_cannot_read_comes_back_as_a_form_error(): void
    {
        FakeProcesses::install()->lsRemote = '';

        $this->actingAs(User::factory()->owner()->create())->from('/applications/create')
            ->post('/applications', $this->validForm(['repository' => 'acme/missing']))
            ->assertRedirect('/applications/create')
            ->assertSessionHasErrors('repository');

        $this->assertSame(0, Application::count());
    }

    public function test_the_form_is_validated(): void
    {
        $owner = User::factory()->owner()->create();
        $this->mock(CreateApplication::class)->shouldNotReceive('handle');

        $cases = [
            'repository' => [['repository' => ''], 'repository'],
            'a repository that is not owner/repo or https' => [['repository' => 'git@github.com:acme/shop.git'], 'repository'],
            'an unknown cluster' => [['cluster_id' => 999], 'cluster_id'],
            'an unknown preset' => [['type' => 'rails'], 'type'],
            'an unknown env profile' => [['env_profile' => 'django'], 'env_profile'],
            'a port above 65535' => [['type' => 'dockerfile', 'port' => 65536], 'port'],
            'a maximum below the minimum' => [['min_instances' => 3, 'max_instances' => 2], 'max_instances'],
            'an unknown size' => [['size' => 'xl'], 'size'],
            'a cpu target of zero' => [['cpu_target' => 0], 'cpu_target'],
            'an env key with a dash' => [['env' => [['key' => 'MY-KEY', 'value' => 'x']]], 'env.0.key'],
            'a duplicate env key' => [['env' => [['key' => 'A', 'value' => '1'], ['key' => 'A', 'value' => '2']]], 'env.0.key'],
            'a domain that is not a host name' => [['domain' => 'not a domain'], 'domain'],
            'an unknown database' => [['database' => 'oracle'], 'database'],
            'a process list that is not a list' => [['processes' => 'worker'], 'processes'],
        ];

        foreach ($cases as [$overrides, $field]) {
            $this->actingAs($owner)->post('/applications', $this->validForm($overrides))->assertSessionHasErrors($field);
        }
    }

    public function test_each_process_row_is_validated_with_a_plain_message(): void
    {
        $owner = User::factory()->owner()->create();
        $this->mock(CreateApplication::class)->shouldNotReceive('handle');
        $row = fn (array $values) => ['name' => 'worker', 'command' => 'php artisan queue:work', 'instances' => 1, 'exclusive' => false, ...$values];

        $cases = [
            [[$row(['name' => 'web'])], ['processes.0.name' => 'Opencloud uses the names web, deploy, and command. Use a different name.']],
            [[$row(['name' => 'Queue Worker'])], ['processes.0.name' => 'Use 1 to 30 lowercase letters, digits, and dashes. Start and end with a letter or a digit.']],
            [[$row([]), $row(['command' => 'php artisan horizon'])], ['processes.1.name' => 'Two processes have this name. Give each process its own name.']],
            [[$row(['command' => ''])], ['processes.0.command' => 'Enter the command that starts the process.']],
            [[$row(['instances' => 21])], ['processes.0.instances' => 'Use 1 to 20 instances.']],
            [[$row([]), $row(['name' => 'scheduler', 'instances' => 2, 'exclusive' => true])], ['processes.1.instances' => 'An exclusive process runs exactly one instance.']],
        ];

        foreach ($cases as [$processes, $errors]) {
            $this->actingAs($owner)->post('/applications', $this->validForm(['processes' => $processes]))->assertSessionHasErrors($errors);
        }
    }

    public function test_a_domain_that_exists_is_refused(): void
    {
        Domain::factory()->for($this->makeEnvironment())->create(['hostname' => 'taken.example.com']);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/applications', $this->validForm(['domain' => 'taken.example.com']))
            ->assertSessionHasErrors(['domain' => 'This domain is already in use.']);
    }

    public function test_the_application_page_lists_production_first_then_permanent_then_previews(): void
    {
        $production = $this->makeEnvironment(name: 'production');
        $application = $production->application;
        Environment::factory()->for($application)->create(['name' => 'pr-7', 'kind' => EnvironmentKind::Preview, 'pr_number' => 7]);
        Environment::factory()->for($application)->create(['name' => 'staging', 'kind' => EnvironmentKind::Permanent]);
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PreviewTemplate]);
        Deployment::factory()->for($production)->create(['status' => DeploymentStatus::Live]);
        Domain::factory()->for($production)->create(['hostname' => 'shop.example.com']);

        $this->actingAs(User::factory()->create())->get("/applications/{$application->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->where('application.name', 'shop')
                ->has('environments', 3)
                ->where('environments.0.name', 'production')
                ->where('environments.0.status', 'live')
                ->where('environments.0.url', 'https://shop.example.com')
                ->where('environments.1.name', 'staging')
                ->where('environments.2.name', 'pr-7')
                ->where('environments.2.pr_number', 7));
    }
}
