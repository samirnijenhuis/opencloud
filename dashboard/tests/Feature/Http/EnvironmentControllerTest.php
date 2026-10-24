<?php

namespace Tests\Feature\Http;

use App\Actions\UpdateEnvironmentSettings;
use App\Enums\DeploymentStatus;
use App\Enums\Size;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Domain;
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

    public function test_the_overview_shows_the_status_the_live_commit_the_urls_and_the_rights(): void
    {
        $environment = $this->makeEnvironment();
        $author = User::factory()->create(['name' => 'Dev']);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Live, 'commit_sha' => str_repeat('a', 40)]);
        Deployment::factory()->for($environment)->create(['status' => DeploymentStatus::Building, 'commit_sha' => str_repeat('b', 40), 'triggered_by' => $author->id]);
        Domain::factory()->for($environment)->create(['hostname' => 'shop.example.com']);

        $this->actingAs(User::factory()->create())->get("/environments/{$environment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/show')
                ->where('application.name', 'shop')
                ->where('environment.status', 'building')
                ->where('environment.live_commit', str_repeat('a', 40))
                ->where('environment.urls', ['https://shop.example.com'])
                ->where('environment.is_protected', true)
                ->where('latestDeployment.triggered_by', 'Dev')
                ->where('can', ['update' => false, 'deploy' => false, 'manage_domains' => false]));
    }

    public function test_the_rights_follow_the_role_and_the_protection_of_the_environment(): void
    {
        $protected = $this->makeEnvironment(true, 'production');
        $open = $this->makeEnvironment(false, 'staging');

        $this->actingAs(User::factory()->create())->get("/environments/{$open->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can', ['update' => true, 'deploy' => true, 'manage_domains' => false]));
        $this->actingAs(User::factory()->admin()->create())->get("/environments/{$protected->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can', ['update' => true, 'deploy' => true, 'manage_domains' => true]));
    }

    public function test_the_settings_page_shows_the_current_settings(): void
    {
        $environment = $this->makeEnvironment(attributes: ['size' => Size::M, 'deploy_commands' => ['php artisan migrate --force'], 'branch' => 'main']);

        $this->actingAs(User::factory()->owner()->create())->get("/environments/{$environment->id}/settings")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/settings')
                ->where('settings.size', 'm')
                ->where('settings.branch', 'main')
                ->where('settings.health_path', '/up')
                ->where('settings.deploy_commands', ['php artisan migrate --force'])
                ->has('sizes', 4));
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
