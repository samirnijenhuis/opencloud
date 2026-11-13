<?php

namespace Tests\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use App\Domains\Environments\ToolLogin;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreviewSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PLACEMENT = 'Put {pr_number} once in the first part of the address, for example pr-{pr_number}.previews.example.com.';

    private const HOST = 'Enter an address like pr-{pr_number}.previews.example.com.';

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

    public function test_the_application_page_shows_the_previews_with_their_pull_request_and_the_row_switch(): void
    {
        $this->actingAs(User::factory()->create())->get("/applications/{$this->application->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->has('environments', 3)
                ->where('environments.1.name', 'preview-template')
                ->where('environments.2.name', 'pr-12')
                ->where('environments.2.pr_url', 'https://github.com/acme/shop/pull/12')
                ->where('environments.0.pr_url', null)
                ->where('previews', ['previews_enabled' => true, 'max_previews' => 5])
                ->where('canUpdate', false));
    }

    public function test_the_template_canvas_shows_the_preview_settings_and_the_production_canvas_shows_none(): void
    {
        $this->application->update(['preview_domain' => 'pr-{pr_number}.previews.example.com', 'preview_labels' => ['preview']]);
        $this->actingAs(User::factory()->create());

        $this->get("/environments/{$this->template->id}")->assertInertia(fn (Assert $page) => $page
            ->where('previews', [
                'previews_enabled' => true,
                'address' => 'pr-{pr_number}.previews.example.com',
                'preview_domain' => 'pr-{pr_number}.previews.example.com',
                'preview_labels' => ['preview'],
                'ingress_ip' => '203.0.113.10',
                'max_previews' => 5,
                'attachments' => [],
            ]));

        $this->application->update(['preview_domain' => null]);
        $this->get("/environments/{$this->template->id}")->assertInertia(fn (Assert $page) => $page
            ->where('previews.address', 'pr-{pr_number}-shop.203.0.113.10.sslip.io')
            ->where('previews.preview_domain', null));

        $production = $this->application->environments()->where('name', 'production')->sole();
        $this->get("/environments/{$production->id}")->assertInertia(fn (Assert $page) => $page->where('previews', null));
    }

    public function test_the_template_canvas_shows_its_list_and_its_menu_offers_what_the_list_can_still_take(): void
    {
        $this->template->update(['preview_attachments' => [
            ['kind' => 'mysql', 'name' => 'main', 'is_default' => true],
            ['kind' => 'valkey', 'name' => null, 'is_default' => false],
        ]]);
        // A bucket that an older release attached to the template stays on the page, but it does not count for the list.
        $bucket = DatastoreAttachment::factory()->create([
            'datastore_id' => Datastore::factory()->for($this->application->cluster)->create(['kind' => DatastoreKind::RUSTFS])->id,
            'environment_id' => $this->template->id,
            'name' => 'main',
            'is_default' => true,
        ]);

        $page = $this->actingAs(User::factory()->create())->get("/environments/{$this->template->id}")->viewData('page');

        $this->assertSame([
            ['kind' => 'mysql', 'name' => 'main', 'is_default' => true, 'family' => 'database', 'label' => 'MySQL'],
            ['kind' => 'valkey', 'name' => null, 'is_default' => false, 'family' => 'key_value', 'label' => 'Valkey'],
        ], json_decode(json_encode($page['props']['previews']['attachments']), true));
        $this->assertSame([$bucket->id], array_column(json_decode(json_encode($page['props']['datastores']['attachments']), true), 'id'));
        $this->assertSame(
            [['standard', 'mysql', ''], ['standard', 'postgres', ''], ['standard', 'rustfs', 'main']],
            array_map(fn (array $offer) => [$offer['source'], $offer['kind'], $offer['name_field']['value']], json_decode(json_encode($page['props']['datastores']['offers']), true)),
        );
    }

    public function test_an_admin_saves_the_address_and_the_labels_from_the_template_sheet(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['preview_domain' => ' PR-{pr_number}.Previews.Example.com. ', 'preview_labels' => ' preview, Deploy me ,, '])
            ->assertSessionHasNoErrors();

        $this->application->refresh();
        $this->assertSame([true, 'pr-{pr_number}.previews.example.com', ['preview', 'Deploy me']], [$this->application->previews_enabled, $this->application->preview_domain, $this->application->preview_labels]);
        $this->assertSame(
            ['application.previews_updated', ['changes' => [
                'preview_domain' => ['from' => null, 'to' => 'pr-{pr_number}.previews.example.com'],
                'preview_labels' => ['from' => [], 'to' => ['preview', 'Deploy me']],
            ]]],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );

        $this->patch("/applications/{$this->application->id}/previews", ['preview_domain' => '', 'preview_labels' => ''])->assertSessionHasNoErrors();
        $this->assertSame([null, []], [$this->application->fresh()->preview_domain, $this->application->fresh()->preview_labels]);
    }

    public function test_the_row_switch_changes_only_previews_enabled(): void
    {
        $this->application->update(['preview_domain' => 'pr-{pr_number}.previews.example.com', 'preview_labels' => ['preview']]);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => false])
            ->assertSessionHasNoErrors();

        $this->application->refresh();
        $this->assertSame([false, 'pr-{pr_number}.previews.example.com', ['preview']], [$this->application->previews_enabled, $this->application->preview_domain, $this->application->preview_labels]);
        $this->assertSame(['changes' => ['previews_enabled' => ['from' => true, 'to' => false]]], AuditEvent::sole()->context);
    }

    public function test_an_admin_sets_the_limit_and_both_pages_show_it(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['max_previews' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->application->fresh()->max_previews);
        $this->assertSame(['changes' => ['max_previews' => ['from' => 5, 'to' => 0]]], AuditEvent::sole()->context);
        $this->get("/applications/{$this->application->id}")->assertInertia(fn (Assert $page) => $page->where('previews.max_previews', 0));
        $this->get("/environments/{$this->template->id}")->assertInertia(fn (Assert $page) => $page->where('previews.max_previews', 0));
    }

    public function test_a_limit_outside_0_to_1000_is_refused(): void
    {
        foreach ([-1, 1001, 'many'] as $limit) {
            $this->actingAs(User::factory()->admin()->create())
                ->patch("/applications/{$this->application->id}/previews", ['max_previews' => $limit])
                ->assertSessionHasErrors(['max_previews' => 'Enter a number from 0 to 1000. 0 means no limit.']);
        }

        $this->assertSame(5, $this->application->fresh()->max_previews);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_preview_canvas_shows_its_running_tools_and_their_login_and_production_shows_none(): void
    {
        // phpMyAdmin does not run, because the preview has no default MySQL database.
        $preview = $this->application->environments()->where('name', 'pr-12')->sole();
        Tool::factory()->for($preview)->create(['kind' => ToolKind::MAILPIT, 'hostname' => 'mail-12.previews.example.com']);
        Tool::factory()->for($preview)->create(['kind' => ToolKind::PHPMYADMIN, 'hostname' => 'database-12.previews.example.com']);
        $this->actingAs(User::factory()->create());

        $this->get("/environments/{$preview->id}")->assertInertia(fn (Assert $page) => $page->where('tools', [
            'links' => [['value' => 'mailpit', 'label' => 'Mailpit', 'url' => 'https://mail-12.previews.example.com']],
            'login' => ['username' => 'opencloud', 'password' => ToolLogin::for($preview)->password],
        ]));

        $production = $this->application->environments()->where('name', 'production')->sole();
        $this->get("/environments/{$production->id}")->assertInertia(fn (Assert $page) => $page->where('tools', null));
    }

    public function test_a_pattern_that_a_tool_of_each_preview_uses_is_refused(): void
    {
        Tool::factory()->for($this->template)->create(['hostname' => 'mail-{pr_number}.previews.example.com']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['preview_domain' => 'mail-{pr_number}.previews.example.com'])
            ->assertSessionHasErrors(['preview_domain' => 'A tool of each preview uses this address. Use a different address.']);

        $this->assertNull($this->application->fresh()->preview_domain);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_save_that_changes_nothing_records_nothing(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => true, 'preview_labels' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AuditEvent::count());
    }

    /** @return array<string, array{string, string}> */
    public static function wrongAddresses(): array
    {
        return [
            'no placeholder' => ['previews.example.com', self::PLACEMENT],
            'two placeholders' => ['pr-{pr_number}-{pr_number}.example.com', self::PLACEMENT],
            'placeholder after the first dot' => ['pr.{pr_number}.example.com', self::PLACEMENT],
            'not a host name' => ['pr-{pr_number}.not a domain', self::HOST],
            'no domain part' => ['pr-{pr_number}', self::HOST],
        ];
    }

    #[DataProvider('wrongAddresses')]
    public function test_a_wrong_address_is_refused_with_its_message(string $address, string $message): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['preview_domain' => $address])
            ->assertSessionHasErrors(['preview_domain' => $message]);

        $this->assertNull($this->application->fresh()->preview_domain);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_too_long_labels_are_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/applications/{$this->application->id}/previews", ['preview_labels' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['preview_labels' => 'Keep the labels to 500 characters or fewer.']);
    }

    public function test_a_developer_cannot_change_the_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch("/applications/{$this->application->id}/previews", ['previews_enabled' => false, 'preview_domain' => 'pr-{pr_number}.previews.example.com'])
            ->assertForbidden();

        $this->assertSame([true, null, []], [$this->application->fresh()->previews_enabled, $this->application->fresh()->preview_domain, $this->application->fresh()->preview_labels]);
        $this->assertSame(0, AuditEvent::count());
    }
}
