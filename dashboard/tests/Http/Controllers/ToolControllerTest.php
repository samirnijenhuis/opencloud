<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ToolControllerTest extends TestCase
{
    use RefreshDatabase;

    private const IN_USE = 'This address is already in use. Use a different address.';

    private Application $application;

    private Environment $template;

    private Environment $preview;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->application = Application::factory()->create(['name' => 'shop', 'preview_domain' => 'pr-{pr_number}.previews.example.com']);
        $this->template = Environment::factory()->for($this->application)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        $this->preview = Environment::factory()->for($this->application)->create(['name' => 'pr-12', 'kind' => EnvironmentKind::PREVIEW, 'pr_number' => 12]);
        Deployment::factory()->for($this->preview)->create(['status' => DeploymentStatus::LIVE]);
        Domain::factory()->for($this->preview)->create(['hostname' => 'pr-12.previews.example.com']);
    }

    public function test_a_developer_adds_changes_and_removes_a_tool_of_the_preview_template(): void
    {
        $this->actingAs(User::factory()->create())
            ->post("/environments/{$this->template->id}/tools", ['kind' => 'mailpit', 'hostname' => 'https://Mail-{pr_number}.Previews.Example.com/'])
            ->assertSessionHasNoErrors();
        $tool = Tool::sole();
        $this->assertSame([$this->template->id, ToolKind::MAILPIT, 'mail-{pr_number}.previews.example.com'], [$tool->environment_id, $tool->kind, $tool->hostname]);

        $this->post("/environments/{$this->template->id}/tools", ['kind' => 'mailpit', 'hostname' => 'inbox-{pr_number}.previews.example.com'])
            ->assertSessionHasErrors(['kind' => 'This environment already has this tool.']);
        $this->patch("/tools/{$tool->id}", ['hostname' => 'mail-{pr_number}.previews.example.com'])->assertSessionHasNoErrors();
        $this->patch("/tools/{$tool->id}", ['hostname' => 'inbox-{pr_number}.previews.example.com'])->assertSessionHasNoErrors();
        $this->assertSame('inbox-{pr_number}.previews.example.com', $tool->fresh()->hostname);

        $this->delete("/tools/{$tool->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, Tool::count());
        $this->assertSame([
            ['tool.added', ['environment_id' => $this->template->id, 'kind' => 'mailpit', 'hostname' => 'mail-{pr_number}.previews.example.com']],
            ['tool.updated', ['environment_id' => $this->template->id, 'kind' => 'mailpit', 'from' => 'mail-{pr_number}.previews.example.com', 'to' => 'inbox-{pr_number}.previews.example.com']],
            ['tool.removed', ['kind' => 'mailpit', 'hostname' => 'inbox-{pr_number}.previews.example.com']],
        ], AuditEvent::orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->context])->all());
        // The template routes nothing, so its tools need no Caddy sync.
        Queue::assertNothingPushed();
    }

    public function test_on_a_live_preview_a_new_tool_waits_for_the_next_deploy_and_a_change_syncs_caddy(): void
    {
        $this->actingAs(User::factory()->create())
            ->post("/environments/{$this->preview->id}/tools", ['kind' => 'phpmyadmin', 'hostname' => 'database-12.previews.example.com'])
            ->assertSessionHasNoErrors();
        Queue::assertNothingPushed();

        $tool = Tool::sole();
        $this->patch("/tools/{$tool->id}", ['hostname' => 'db-12.previews.example.com'])->assertSessionHasNoErrors();

        $this->assertSame('db-12.previews.example.com', $tool->fresh()->hostname);
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $job) => $job->cluster->is($this->application->cluster));
    }

    public function test_on_a_live_preview_a_removal_syncs_caddy(): void
    {
        $tool = Tool::factory()->for($this->preview)->create(['hostname' => 'mail-12.previews.example.com']);

        $this->actingAs(User::factory()->create())->delete("/tools/{$tool->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, Tool::count());
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $job) => $job->cluster->is($this->application->cluster));
    }

    public function test_a_permanent_environment_gets_no_tool(): void
    {
        $production = Environment::factory()->for($this->application)->create(['name' => 'production']);

        $this->actingAs(User::factory()->owner()->create())
            ->post("/environments/{$production->id}/tools", ['kind' => 'mailpit', 'hostname' => 'mail.example.com'])
            ->assertSessionHasErrors(['kind' => 'Tools run only in previews. Add the tool to the preview template.']);

        $this->assertSame(0, Tool::count());
        $this->assertSame(0, AuditEvent::count());
    }

    /** @return array<string, array{string, string, string}> the environment, the hostname, and the message */
    public static function refusedHostnames(): array
    {
        return [
            'the address of each preview' => ['template', 'pr-{pr_number}.previews.example.com', 'Each preview uses this address. Use a different address.'],
            'the preview address of another application' => ['template', 'pr-{pr_number}.staging.example.com', self::IN_USE],
            'a tool of another application' => ['template', 'mail-{pr_number}.staging.example.com', self::IN_USE],
            'a pattern without the number' => ['template', 'mail.previews.example.com', 'Put {pr_number} once in the first part of the address, for example pr-{pr_number}.previews.example.com.'],
            'a pattern that is not a hostname' => ['template', 'mail-{pr_number}', 'Enter an address like pr-{pr_number}.previews.example.com.'],
            'a domain of the preview' => ['preview', 'pr-12.previews.example.com', self::IN_USE],
            'a domain of another environment' => ['preview', 'shop.example.com', self::IN_USE],
            'a pattern on a preview' => ['preview', 'mail-{pr_number}.previews.example.com', 'Enter an address like mail-12.previews.example.com.'],
            'a first label of 64 characters' => ['preview', str_repeat('a', 64).'.example.com', 'Enter an address like mail-12.previews.example.com.'],
        ];
    }

    #[DataProvider('refusedHostnames')]
    public function test_a_hostname_that_is_in_use_or_breaks_the_rule_is_refused(string $environment, string $hostname, string $message): void
    {
        $other = Application::factory()->for($this->application->cluster)->create(['name' => 'blog', 'preview_domain' => 'pr-{pr_number}.staging.example.com']);
        $otherTemplate = Environment::factory()->for($other)->create(['name' => 'preview-template', 'kind' => EnvironmentKind::PREVIEW_TEMPLATE, 'branch' => null]);
        Tool::factory()->for($otherTemplate)->create(['hostname' => 'mail-{pr_number}.staging.example.com']);
        Domain::factory()->for(Environment::factory()->for($other)->create(['name' => 'production']))->create(['hostname' => 'shop.example.com']);
        $target = $environment === 'template' ? $this->template : $this->preview;

        $this->actingAs(User::factory()->admin()->create())
            ->post("/environments/{$target->id}/tools", ['kind' => 'mailpit', 'hostname' => $hostname])
            ->assertSessionHasErrors(['hostname' => $message]);

        $this->assertSame(0, $target->tools()->count());
        $this->assertSame(0, AuditEvent::count());
    }
}
