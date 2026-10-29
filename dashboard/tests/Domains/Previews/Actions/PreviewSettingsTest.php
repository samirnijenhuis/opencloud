<?php

namespace Tests\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PreviewSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;

    private Environment $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = Application::factory()->for(Cluster::factory()->create(['ingress_ip' => '203.0.113.10']))->create(['name' => 'shop', 'repository' => 'acme/shop']);
        Environment::factory()->for($this->application)->create(['name' => 'production']);
        $this->template = Environment::factory()->for($this->application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        Environment::factory()->for($this->application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12, 'branch' => 'feature/login']);
    }

    public function test_the_application_page_shows_the_previews_with_their_pull_request_and_the_preview_settings(): void
    {
        $this->application->update(['preview_labels' => ['preview']]);

        $this->actingAs(User::factory()->create())->get("/applications/{$this->application->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->has('environments', 2)
                ->where('environments.1.name', 'pr-12')
                ->where('environments.1.pr_url', 'https://github.com/acme/shop/pull/12')
                ->where('environments.0.pr_url', null)
                ->where('previews', [
                    'previews_enabled' => true,
                    'allow_forks' => false,
                    'preview_domain' => null,
                    'preview_labels' => ['preview'],
                    'ingress_ip' => '203.0.113.10',
                    'max_previews' => 5,
                    'template_id' => $this->template->id,
                ])
                ->where('canUpdate', false));
    }

    public function test_an_admin_saves_the_preview_settings_from_a_pasted_wildcard_record(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => false, 'allow_forks' => true, 'preview_domain' => ' *.Preview.Example.com. ', 'preview_labels' => ' preview, Deploy me ,, '])
            ->assertSessionHasNoErrors();

        $this->application->refresh();
        $this->assertSame([false, true, 'preview.example.com', ['preview', 'Deploy me']], [$this->application->previews_enabled, $this->application->allow_forks, $this->application->preview_domain, $this->application->preview_labels]);
        $this->assertSame(
            ['application.previews_updated', ['previews_enabled' => false, 'allow_forks' => true, 'preview_domain' => 'preview.example.com', 'preview_labels' => ['preview', 'Deploy me']]],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );

        $this->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => true, 'allow_forks' => false, 'preview_domain' => '', 'preview_labels' => ''])->assertSessionHasNoErrors();
        $this->assertSame([null, []], [$this->application->fresh()->preview_domain, $this->application->fresh()->preview_labels]);
    }

    public function test_a_wrong_domain_is_refused_and_a_developer_cannot_change_the_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => true, 'allow_forks' => false, 'preview_domain' => 'not a domain'])
            ->assertSessionHasErrors(['preview_domain' => 'Enter a domain like preview.example.com.']);
        $this->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => true, 'allow_forks' => false, 'preview_domain' => '', 'preview_labels' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['preview_labels' => 'Keep the labels to 500 characters or fewer.']);

        $this->actingAs(User::factory()->create())
            ->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => false, 'allow_forks' => true, 'preview_domain' => ''])
            ->assertForbidden();

        $this->assertSame([true, false, null, []], [$this->application->fresh()->previews_enabled, $this->application->fresh()->allow_forks, $this->application->fresh()->preview_domain, $this->application->fresh()->preview_labels]);
        $this->assertSame(0, AuditEvent::count());
    }
}
