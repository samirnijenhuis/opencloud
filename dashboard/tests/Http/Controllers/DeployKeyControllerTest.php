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
        $application = Application::factory()->create(['name' => 'shop']);

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
            [['application.deploy_key_added', $application->id, []], ['application.deploy_key_added', $application->id, []]],
            AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event) => [$event->action, $event->subject_id, $event->context])->all(),
        );
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

    public function test_a_developer_can_neither_add_nor_remove_the_deploy_key(): void
    {
        $processes = FakeProcesses::install();
        $application = Application::factory()->create(['deploy_private_key' => self::OLD_PRIVATE_KEY, 'deploy_public_key' => self::OLD_PUBLIC_KEY]);

        $this->actingAs(User::factory()->create())->post("/applications/{$application->id}/deploy-key")->assertForbidden();
        $this->delete("/applications/{$application->id}/deploy-key")->assertForbidden();

        $application->refresh();
        $this->assertSame([self::OLD_PRIVATE_KEY, self::OLD_PUBLIC_KEY], [$application->deploy_private_key, $application->deploy_public_key]);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame([], $processes->commands());
    }
}
