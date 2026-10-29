<?php

namespace Tests\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\UpdateEnvironmentSettings;
use App\Domains\Environments\Enums\EnvironmentColor;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsEnvironments;
use Tests\Concerns\WithGitHubApp;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class EnvironmentControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase, WithGitHubApp;

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'branch' => 'release',
            'auto_deploy' => false,
            'size' => 'm',
            'min_instances' => 2,
            'max_instances' => 6,
            'cpu_target' => 65,
            'health_path' => '/healthz',
            'build_command' => 'npm run build',
            'deploy_commands' => ['php artisan migrate --force', '', '  php artisan optimize  '],
            ...$overrides,
        ];
    }

    public function test_the_canvas_shows_the_network_the_app_and_the_latest_deployments(): void
    {
        $environment = $this->makeEnvironment(attributes: ['size' => Size::M, 'min_instances' => 2, 'max_instances' => 5, 'cpu_target' => 60, 'health_path' => '/healthz']);
        $author = User::factory()->create(['name' => 'Dev']);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::SUPERSEDED, 'commit_sha' => str_repeat('1', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::LIVE, 'commit_sha' => str_repeat('a', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::FAILED, 'commit_sha' => str_repeat('c', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::BUILDING, 'commit_sha' => str_repeat('b', 40), 'triggered_by' => $author->id]);
        $free = Domain::factory()->for($environment)->create(['hostname' => 'shop-production.203.0.113.10.sslip.io', 'generated' => true, 'dns_status' => DnsStatus::OK, 'certificate_status' => 'issued']);
        Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com']);

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/show')
                ->where('application.name', 'shop')
                ->where('environment.status', 'building')
                ->where('cluster', ['id' => $this->cluster->id, 'name' => 'local', 'kind' => 'local', 'ingress_ip' => '203.0.113.10'])
                ->where('tls_issuer', 'acme')
                ->where('compute', ['size' => 'm', 'min_instances' => 2, 'max_instances' => 5, 'cpu_target' => 60, 'health_path' => '/healthz'])
                ->where('sizes.2', ['value' => 'm', 'cpu_millicores' => 1000, 'memory_mib' => 1024])
                ->where('domains.0.id', $free->id)
                ->where('domains.0.generated', true)
                ->where('domains.0.dns_status', 'ok')
                ->where('domains.1.generated', false)
                ->where('cloudflare_zones', [])
                ->where('deployments.0.commit_sha', str_repeat('b', 40))
                ->where('deployments.0.triggered_by', 'Dev')
                ->where('deployments.1.status', 'failed')
                ->where('deployments.2.status', 'live')
                ->has('deployments', 3)
                ->where('can', ['update' => false, 'deploy' => false, 'manage_domains' => false, 'restore' => false, 'manage_credentials' => false]));
    }

    public function test_the_commands_page_tells_who_may_run_and_read_commands(): void
    {
        $environment = $this->makeEnvironment();

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}/commands")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/commands')
                ->where('can', ['update' => false, 'deploy' => false, 'manage_domains' => false, 'read_logs' => true]));
        $this->actingAs(User::factory()->admin()->create())->get("/environments/{$environment->id}/commands")
            ->assertInertia(fn (Assert $page) => $page->where('can', ['update' => true, 'deploy' => true, 'manage_domains' => true, 'read_logs' => true]));
    }

    public function test_the_old_domains_and_datastores_pages_open_the_canvas(): void
    {
        $environment = $this->makeEnvironment();
        $user = User::factory()->create();

        $this->actingAs($user)->get("/environments/{$environment->id}/domains")->assertRedirect("/environments/{$environment->id}");
        $this->actingAs($user)->get("/environments/{$environment->id}/datastores")->assertRedirect("/environments/{$environment->id}");
    }

    public function test_the_switcher_lists_the_environments_and_the_open_previews_but_not_the_template(): void
    {
        $production = $this->makeEnvironment();
        $application = $production->application;
        $staging = Environment::factory()->for($application)->create(['name' => 'staging', 'color' => EnvironmentColor::GREEN]);
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        $preview = Environment::factory()->for($application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'color' => EnvironmentColor::GRAY]);
        $this->makeEnvironment(false, 'other-application');

        $this->actingAs(User::factory()->create())->get("/environments/{$staging->id}/deployments")
            ->assertInertia(fn (Assert $page) => $page
                ->where('environment.color', 'green')
                ->where('application.environments', [
                    ['id' => $production->id, 'name' => 'production', 'kind' => 'permanent', 'color' => 'blue'],
                    ['id' => $staging->id, 'name' => 'staging', 'kind' => 'permanent', 'color' => 'green'],
                    ['id' => $preview->id, 'name' => 'pr-12', 'kind' => 'preview', 'color' => 'gray'],
                ]));
    }

    public function test_the_rights_follow_the_role_and_the_protection_of_the_environment(): void
    {
        $protected = $this->makeEnvironment(true, 'production');
        $open = $this->makeEnvironment(false, 'staging');

        $this->actingAs(User::factory()->create())->get("/environments/{$open->id}/deployments")
            ->assertInertia(fn (Assert $page) => $page->where('can', ['update' => true, 'deploy' => true, 'manage_domains' => false]));
        $this->actingAs(User::factory()->admin()->create())->get("/environments/{$protected->id}/deployments")
            ->assertInertia(fn (Assert $page) => $page->where('can', ['update' => true, 'deploy' => true, 'manage_domains' => true]));
    }

    public function test_the_settings_page_shows_the_current_settings(): void
    {
        $environment = $this->makeEnvironment(attributes: ['size' => Size::M, 'deploy_commands' => ['php artisan migrate --force'], 'branch' => 'main']);

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/settings")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/settings')
                ->where('settings', ['branch' => 'main', 'auto_deploy' => true, 'build_command' => null, 'deploy_commands' => ['php artisan migrate --force']]));
    }

    public function test_a_developer_cannot_change_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment(true);

        $this->actingAs(User::factory()->create())->patch("/environments/{$environment->id}/settings", $this->settings())->assertForbidden();

        $this->assertSame('main', $environment->fresh()->branch);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_developer_can_change_an_unprotected_environment_and_the_action_gets_clean_values(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');
        $developer = User::factory()->create();

        $this->mock(UpdateEnvironmentSettings::class)
            ->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($given, User $by, array $settings) => $given->is($environment) && $by->is($developer) && $settings === [
                'branch' => 'release',
                'auto_deploy' => false,
                'size' => 'm',
                'min_instances' => 2,
                'max_instances' => 6,
                'cpu_target' => 65,
                'health_path' => '/healthz',
                'build_command' => 'npm run build',
                'deploy_commands' => ['php artisan migrate --force', 'php artisan optimize'],
            ])
            ->andReturn($environment);

        $this->actingAs($developer)->from('/back')->patch("/environments/{$environment->id}/settings", $this->settings())->assertRedirect('/back');
    }

    public function test_the_settings_keep_a_build_command_with_several_lines_and_drop_comment_and_empty_lines(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');

        $this->actingAs(User::factory()->create())->patch("/environments/{$environment->id}/settings", $this->settings([
            'build_command' => "npm run lint\n\n  # build the assets\n  npm run build  \n",
            'deploy_commands' => ['# migrate first', 'php artisan migrate --force', '   ', 'php artisan optimize'],
        ]))->assertSessionHasNoErrors();

        $environment->refresh();
        $this->assertSame("npm run lint\nnpm run build", $environment->build_command);
        $this->assertSame(['php artisan migrate --force', 'php artisan optimize'], $environment->deploy_commands);
    }

    public function test_a_build_command_with_only_comments_clears_the_build_command(): void
    {
        $environment = $this->makeEnvironment(false, 'staging', ['build_command' => 'npm run build']);

        $this->actingAs(User::factory()->create())->patch("/environments/{$environment->id}/settings", $this->settings(['build_command' => "# nothing\n"]))
            ->assertSessionHasNoErrors();

        $this->assertNull($environment->refresh()->build_command);
    }

    public function test_an_admin_saves_the_settings_of_a_protected_environment(): void
    {
        $environment = $this->makeEnvironment(true);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch("/environments/{$environment->id}/settings", $this->settings())->assertRedirect();

        $environment->refresh();
        $this->assertSame(['release', false, Size::M, 2, 6, 65, '/healthz', 'npm run build'], [
            $environment->branch, $environment->auto_deploy, $environment->size, $environment->min_instances,
            $environment->max_instances, $environment->cpu_target, $environment->health_path, $environment->build_command,
        ]);
        $this->assertSame(['php artisan migrate --force', 'php artisan optimize'], $environment->deploy_commands);

        $event = AuditEvent::sole();
        $this->assertSame(['environment.updated', $admin->id, $environment->id], [$event->action, $event->user_id, $event->subject_id]);
        $this->assertSame(['from' => 'main', 'to' => 'release'], $event->context['changes']['branch']);
        $this->assertSame(['from' => 's', 'to' => 'm'], $event->context['changes']['size']);
    }

    public function test_the_auto_deploy_switch_sends_one_field(): void
    {
        $environment = $this->makeEnvironment(false, 'staging');

        $this->actingAs(User::factory()->create())->patch("/environments/{$environment->id}/settings", ['auto_deploy' => false])->assertRedirect();

        $this->assertFalse($environment->fresh()->auto_deploy);
        $this->assertSame(['auto_deploy' => ['from' => true, 'to' => false]], AuditEvent::sole()->context['changes']);
    }

    public function test_saving_unchanged_settings_records_nothing(): void
    {
        $environment = $this->makeEnvironment(false);

        $this->actingAs(User::factory()->create())->patch("/environments/{$environment->id}/settings", ['auto_deploy' => true])->assertRedirect();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_the_settings_are_validated(): void
    {
        $environment = $this->makeEnvironment(false);
        $developer = User::factory()->create();

        $cases = [
            ['branch', ''],
            ['branch', 'has space'],
            ['size', 'xxl'],
            ['min_instances', 0],
            ['cpu_target', 100],
            ['health_path', 'up'],
            ['deploy_commands', 'not an array'],
        ];

        foreach ($cases as [$field, $value]) {
            $this->actingAs($developer)->patch("/environments/{$environment->id}/settings", [$field => $value])->assertSessionHasErrors($field);
        }

        $this->actingAs($developer)->patch("/environments/{$environment->id}/settings", ['min_instances' => 4, 'max_instances' => 2])->assertSessionHasErrors('max_instances');
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_minimum_above_the_stored_maximum_is_refused(): void
    {
        $environment = $this->makeEnvironment(false, attributes: ['max_instances' => 2]);

        $this->actingAs(User::factory()->create())->patch("/environments/{$environment->id}/settings", ['min_instances' => 5])
            ->assertSessionHasErrors('max_instances');

        $this->assertSame(1, $environment->fresh()->min_instances);
    }

    public function test_an_admin_adds_staging_for_the_branch_staging_and_a_push_to_staging_deploys_it(): void
    {
        Queue::fake([RunDeployment::class, CheckDomain::class]);
        FakeProcesses::install()->lsRemote = str_repeat('a', 40)."\trefs/heads/staging\n";
        $production = $this->makeEnvironment();
        $application = $production->application;
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE]);

        $response = $this->actingAs(User::factory()->admin()->create())->post("/applications/{$application->id}/environments", [
            'name' => 'staging',
            'branch' => 'staging',
            'copy_from' => 'production',
        ]);

        $staging = $application->environments()->where('name', 'staging')->sole();
        $response->assertRedirect(route('environments.show', $staging));
        $this->assertSame([EnvironmentKind::PERMANENT, 'staging', true], [$staging->kind, $staging->branch, $staging->auto_deploy]);

        $this->makeGitHubApp();
        $this->postWebhook('push', [
            'ref' => 'refs/heads/staging',
            'before' => str_repeat('a', 40),
            'after' => str_repeat('b', 40),
            'deleted' => false,
            'repository' => ['full_name' => 'acme/shop'],
        ])->assertStatus(202);

        $this->assertSame(
            [['staging', str_repeat('a', 40)], ['staging', str_repeat('b', 40)]],
            Deployment::query()->orderBy('id')->with('environment')->get()->map(fn (Deployment $d) => [$d->environment->name, $d->commit_sha])->all(),
        );
        Queue::assertPushedOn('builds', RunDeployment::class);
    }

    public function test_the_new_environment_form_is_validated(): void
    {
        Queue::fake();
        $production = $this->makeEnvironment();
        $application = $production->application;
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        $admin = User::factory()->admin()->create();

        $cases = [
            ['name', 'production', 'This application already has an environment with this name.'],
            ['name', 'preview-template', 'This application already has an environment with this name.'],
            ['name', 'pr-12', 'A name like pr-12 belongs to the preview of a pull request. Choose another name.'],
            ['name', 'Staging', 'Use 1 to 20 lowercase letters, digits, and dashes. Start and end with a letter or a digit.'],
            ['name', 'staging-environment-2', 'Use 1 to 20 lowercase letters, digits, and dashes. Start and end with a letter or a digit.'],
            ['name', '', 'Give the environment a name.'],
            ['branch', 'has space', 'Enter a branch name, for example staging.'],
            ['copy_from', 'staging', 'Choose production or the preview template.'],
        ];

        foreach ($cases as [$field, $value, $message]) {
            $this->actingAs($admin)->post("/applications/{$application->id}/environments", [
                'name' => 'staging',
                'branch' => 'staging',
                'copy_from' => 'production',
                $field => $value,
            ])->assertSessionHasErrors([$field => $message]);
        }

        $this->assertSame(2, Environment::count());
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_a_developer_can_neither_add_nor_delete_an_environment(): void
    {
        Queue::fake();
        $kubectl = FakeKubectl::install();
        $production = $this->makeEnvironment();
        $staging = Environment::factory()->for($production->application)->create(['name' => 'staging']);
        $developer = User::factory()->create();

        $this->actingAs($developer)->post("/applications/{$production->application_id}/environments", ['name' => 'qa', 'branch' => 'qa', 'copy_from' => 'production'])
            ->assertForbidden();
        $this->actingAs($developer)->delete("/environments/{$staging->id}", ['confirm' => 'staging'])->assertForbidden();

        $this->assertSame(2, Environment::count());
        $this->assertSame([], $kubectl->calls);
        $this->assertSame(0, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_deleting_an_environment_needs_its_name_and_then_opens_the_application_page(): void
    {
        Queue::fake();
        $kubectl = FakeKubectl::install();
        $production = $this->makeEnvironment();
        $staging = Environment::factory()->for($production->application)->create(['name' => 'staging']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete("/environments/{$staging->id}", ['confirm' => 'production'])
            ->assertSessionHasErrors(['confirm' => 'Type the name of the environment to confirm.']);
        $this->assertSame([], $kubectl->calls);
        $this->assertNotNull($staging->fresh());

        $this->actingAs($admin)->delete("/environments/{$staging->id}", ['confirm' => 'staging'])
            ->assertRedirect(route('applications.show', $production->application));
        $this->assertNull($staging->fresh());
        $kubectl->assertRan("delete namespace oc-env-{$staging->id}");
    }

    public function test_production_a_preview_and_the_preview_template_cannot_be_deleted(): void
    {
        Queue::fake();
        $kubectl = FakeKubectl::install();
        $production = $this->makeEnvironment();
        $application = $production->application;
        $template = Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        $preview = Environment::factory()->for($application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12]);
        $owner = User::factory()->owner()->create();

        foreach ([$production, $template, $preview] as $environment) {
            $this->actingAs($owner)->get("/environments/{$environment->id}/settings")->assertInertia(fn (Assert $page) => $page->where('canDelete', false));
            $this->actingAs($owner)->delete("/environments/{$environment->id}", ['confirm' => $environment->name])->assertForbidden();
        }

        $this->assertSame(3, Environment::count());
        $this->assertSame([], $kubectl->calls);

        $staging = Environment::factory()->for($application)->create(['name' => 'staging']);
        $this->actingAs($owner)->get("/environments/{$staging->id}/settings")->assertInertia(fn (Assert $page) => $page->where('canDelete', true));
        $this->actingAs(User::factory()->create())->get("/environments/{$staging->id}/settings")->assertInertia(fn (Assert $page) => $page->where('canDelete', false));
    }
}
