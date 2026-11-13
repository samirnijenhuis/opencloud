<?php

namespace Tests\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class DeployKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PRIVATE_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nold-private-key\n-----END OPENSSH PRIVATE KEY-----\n";

    private const OLD_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOldPublicKey opencloud shop';

    public function test_an_admin_adds_a_deploy_key_and_a_new_key_replaces_the_old_one(): void
    {
        $processes = FakeProcesses::install();
        $application = Application::factory()->create(['name' => 'shop', 'repository' => 'acme/shop']);

        $this->actingAs(User::factory()->admin()->create())->from("/applications/{$application->id}")
            ->post("/applications/{$application->id}/deploy-key")
            ->assertRedirect("/applications/{$application->id}");

        $application->refresh();
        $this->assertSame([FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' opencloud shop'], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'opencloud shop', '-f'], array_slice($processes->commands()[0], 0, -1));

        $application->update(['deploy_private_key' => self::OLD_PRIVATE_KEY, 'deploy_public_key' => self::OLD_PUBLIC_KEY]);
        $this->post("/applications/{$application->id}/deploy-key")->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame([FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' opencloud shop'], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(
            [['application.deploy_key_added', $application->id, ['source' => 'new']], ['application.deploy_key_added', $application->id, ['source' => 'new']]],
            AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->subject_id, $event->context])->all(),
        );
    }

    public function test_an_admin_uses_an_own_key_in_place_of_the_old_key_and_the_page_never_shows_the_private_key(): void
    {
        $processes = FakeProcesses::install();
        $application = Application::factory()->create(['repository' => 'acme/shop', 'deploy_private_key' => self::OLD_PRIVATE_KEY, 'deploy_public_key' => self::OLD_PUBLIC_KEY]);

        // The form field arrives without its last newline, because Laravel trims each string.
        $this->actingAs(User::factory()->admin()->create())->from("/applications/{$application->id}")
            ->post("/applications/{$application->id}/deploy-key", ['deploy_private_key' => trim(FakeProcesses::PRIVATE_KEY)])
            ->assertRedirect("/applications/{$application->id}")
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame([FakeProcesses::PRIVATE_KEY, FakeProcesses::PUBLIC_KEY.' me@example.com'], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame([['ssh-keygen', '-y', '-P', '', '-f']], array_map(fn (array $command) => array_slice($command, 0, -1), $processes->commands()));
        $this->assertSame(['application.deploy_key_added', ['source' => 'own']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);
        $this->get("/applications/{$application->id}")->assertOk()->assertDontSee('fake-private-key');
    }

    public function test_a_deploy_key_for_a_repository_outside_github_is_refused_and_nothing_changes(): void
    {
        $processes = FakeProcesses::install();
        $application = Application::factory()->create(['repository' => 'https://gitlab.com/acme/shop.git']);
        $this->actingAs(User::factory()->admin()->create())->from("/applications/{$application->id}");

        foreach ([[], ['deploy_private_key' => FakeProcesses::PRIVATE_KEY]] as $body) {
            $this->post("/applications/{$application->id}/deploy-key", $body)
                ->assertRedirect("/applications/{$application->id}")
                ->assertSessionHasErrors(['deploy_private_key' => 'A deploy key works only for a repository on github.com.']);
        }

        $application->refresh();
        $this->assertSame([null, null], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame([0, []], [AuditEvent::query()->count(), $processes->commands()]);
    }

    public function test_a_key_that_ssh_keygen_cannot_read_gives_the_message_keeps_the_old_key_and_never_reaches_the_session(): void
    {
        FakeProcesses::install();
        $application = Application::factory()->create(['repository' => 'acme/shop', 'deploy_private_key' => self::OLD_PRIVATE_KEY, 'deploy_public_key' => self::OLD_PUBLIC_KEY]);

        $this->actingAs(User::factory()->admin()->create())->from("/applications/{$application->id}")
            ->post("/applications/{$application->id}/deploy-key", ['deploy_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nsecret-key-text\n-----END OPENSSH PRIVATE KEY-----", 'note' => 'kept'])
            ->assertRedirect("/applications/{$application->id}")
            ->assertSessionHasErrors(['deploy_private_key' => 'Paste an OpenSSH private key without a passphrase.']);

        $application->refresh();
        $this->assertSame([self::OLD_PRIVATE_KEY, self::OLD_PUBLIC_KEY], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(['kept', null], [session()->getOldInput('note'), session()->getOldInput('deploy_private_key')]);
        $this->get("/applications/{$application->id}")->assertDontSee('secret-key-text');
    }

    public function test_an_owner_removes_the_deploy_key(): void
    {
        $processes = FakeProcesses::install();
        $application = Application::factory()->create(['deploy_private_key' => self::OLD_PRIVATE_KEY, 'deploy_public_key' => self::OLD_PUBLIC_KEY]);

        $this->actingAs(User::factory()->owner()->create())->from("/applications/{$application->id}")
            ->delete("/applications/{$application->id}/deploy-key")
            ->assertRedirect("/applications/{$application->id}");

        $application->refresh();
        $this->assertSame([null, null], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(['application.deploy_key_removed', $application->id, []], [AuditEvent::sole()->action, AuditEvent::sole()->subject_id, AuditEvent::sole()->context]);
        $this->assertSame([], $processes->commands());
    }

    public function test_a_developer_can_neither_add_nor_use_nor_remove_the_deploy_key(): void
    {
        $processes = FakeProcesses::install();
        $application = Application::factory()->create(['deploy_private_key' => self::OLD_PRIVATE_KEY, 'deploy_public_key' => self::OLD_PUBLIC_KEY]);

        $this->actingAs(User::factory()->create())->post("/applications/{$application->id}/deploy-key")->assertForbidden();
        $this->post("/applications/{$application->id}/deploy-key", ['deploy_private_key' => FakeProcesses::PRIVATE_KEY])->assertForbidden();
        $this->delete("/applications/{$application->id}/deploy-key")->assertForbidden();

        $application->refresh();
        $this->assertSame([self::OLD_PRIVATE_KEY, self::OLD_PUBLIC_KEY], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame([], $processes->commands());
    }
}
