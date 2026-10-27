<?php

namespace Tests\Feature\Members;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MembersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_developer_sees_the_members_read_only(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Olivia']);
        $developer = User::factory()->create(['name' => 'Dev']);

        $this->actingAs($developer)->get('/settings/members')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/members')
                ->where('canInvite', false)
                ->where('invites', [])
                ->has('members', 2)
                ->where('members.0', ['id' => $owner->id, 'name' => 'Olivia', 'email' => $owner->email, 'role' => 'owner', 'can_manage' => false])
                ->where('members.1', ['id' => $developer->id, 'name' => 'Dev', 'email' => $developer->email, 'role' => 'developer', 'can_manage' => false]));
    }

    public function test_the_owner_can_manage_everybody_but_themselves(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->admin()->create();

        $this->actingAs($owner)->get('/settings/members')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canInvite', true)
                ->where('roles', ['owner', 'admin', 'developer'])
                ->where('members.0.can_manage', false)
                ->where('members.1.can_manage', true));
    }

    public function test_the_owner_changes_the_role_of_a_member(): void
    {
        $owner = User::factory()->owner()->create();
        $developer = User::factory()->create();

        $this->actingAs($owner)->patch("/settings/members/{$developer->id}", ['role' => 'admin'])
            ->assertRedirect('/settings/members');

        $this->assertSame(Role::Admin, $developer->fresh()->role);
        $event = AuditEvent::sole();
        $this->assertSame('member.role_changed', $event->action);
        $this->assertSame(['from' => 'developer', 'to' => 'admin'], $event->context);
    }

    public function test_the_owner_cannot_change_their_own_role(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->patch("/settings/members/{$owner->id}", ['role' => 'developer'])->assertForbidden();

        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }

    public function test_a_role_must_be_a_known_role(): void
    {
        $owner = User::factory()->owner()->create();
        $developer = User::factory()->create();

        $this->actingAs($owner)->patch("/settings/members/{$developer->id}", ['role' => 'root'])->assertSessionHasErrors('role');

        $this->assertSame(Role::Developer, $developer->fresh()->role);
    }

    public function test_an_admin_cannot_change_roles_or_remove_members(): void
    {
        $admin = User::factory()->admin()->create();
        $developer = User::factory()->create();

        $this->actingAs($admin)->patch("/settings/members/{$developer->id}", ['role' => 'owner'])->assertForbidden();
        $this->actingAs($admin)->delete("/settings/members/{$developer->id}")->assertForbidden();

        $this->assertSame(Role::Developer, $developer->fresh()->role);
    }

    public function test_the_owner_removes_a_member(): void
    {
        $owner = User::factory()->owner()->create();
        $developer = User::factory()->create(['email' => 'leaver@example.com']);

        $this->actingAs($owner)->delete("/settings/members/{$developer->id}")->assertRedirect('/settings/members');

        $this->assertNull($developer->fresh());
        $event = AuditEvent::sole();
        $this->assertSame('member.removed', $event->action);
        $this->assertSame(['email' => 'leaver@example.com', 'role' => 'developer'], $event->context);
    }

    public function test_the_owner_cannot_remove_themselves(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->delete("/settings/members/{$owner->id}")->assertForbidden();

        $this->assertNotNull($owner->fresh());
    }

    public function test_the_last_owner_cannot_delete_their_own_account(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasErrors(['password' => 'Make another member an owner before you delete the last owner account.']);

        $this->assertNotNull($owner->fresh());
    }

    public function test_an_owner_can_delete_their_account_when_another_owner_exists(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->actingAs($owner)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertNull($owner->fresh());
    }
}
