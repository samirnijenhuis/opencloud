<?php

namespace Tests\Feature\Http;

use App\Actions\UpdateEnvironmentSettings;
use App\Domains\DnsStatus;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\Size;
use App\Environments\EnvironmentColor;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnvironmentControllerTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

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
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Superseded, 'commit_sha' => str_repeat('1', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Live, 'commit_sha' => str_repeat('a', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Failed, 'commit_sha' => str_repeat('c', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Building, 'commit_sha' => str_repeat('b', 40), 'triggered_by' => $author->id]);
        $free = Domain::factory()->for($environment)->create(['hostname' => 'shop-production.203.0.113.10.sslip.io', 'generated' => true, 'dns_status' => DnsStatus::Ok, 'certificate_status' => 'issued']);
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
        $staging = Environment::factory()->for($application)->create(['name' => 'staging', 'color' => EnvironmentColor::Green]);
        Environment::factory()->for($application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PreviewTemplate]);
        $preview = Environment::factory()->for($application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::Preview, 'color' => EnvironmentColor::Gray]);
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
            ->shouldReceive('handle')
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
}
