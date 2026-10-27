<?php

namespace Tests\Domains\Members\Models;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\Invite;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_MEMBER = [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ];

    private function createInviteLink(Role $role): string
    {
        $owner = User::factory()->owner()->create();

        $link = $this->actingAs($owner)
            ->post('/settings/invites', ['role' => $role->value])
            ->assertRedirect('/settings/members')
            ->assertSessionHas('invite_link')
            ->getSession()->get('invite_link');

        $this->post('/logout');

        return $link;
    }

    public function test_an_owner_gets_a_link_once_and_the_database_keeps_only_a_hash(): void
    {
        $this->freezeSecond();
        $link = $this->createInviteLink(Role::Admin);

        $this->assertMatchesRegularExpression('#^http://localhost/invites/[A-Za-z0-9]{48}$#', $link);
        $token = basename($link);

        $invite = Invite::sole();
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertSame(Role::Admin, $invite->role);
        $this->assertSame(now()->addDays(7)->toDateTimeString(), $invite->expires_at->toDateTimeString());
        $this->assertSame(['invite.created'], AuditEvent::pluck('action')->all());
    }

    public function test_the_members_page_shows_the_new_link_to_the_owner(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->followingRedirects()
            ->post('/settings/invites', ['role' => 'developer'])
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/members')
                ->where('inviteLink', fn (string $link) => str_starts_with($link, 'http://localhost/invites/'))
                ->where('invites.0.role', 'developer'));
    }

    public function test_only_an_owner_can_create_or_revoke_an_invite(): void
    {
        $invite = Invite::factory()->create();

        foreach ([User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->post('/settings/invites', ['role' => 'admin'])->assertForbidden();
            $this->actingAs($user)->delete("/settings/invites/{$invite->id}")->assertForbidden();
        }

        $this->assertSame([$invite->id], Invite::pluck('id')->all());
    }

    public function test_accepting_an_invite_creates_the_member_with_the_invited_role(): void
    {
        $link = $this->createInviteLink(Role::Admin);

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('invites/accept')->where('role', 'admin'));

        $this->post($link, self::NEW_MEMBER)->assertRedirect('/dashboard');

        $member = User::where('email', 'grace@example.com')->sole();
        $this->assertSame(Role::Admin, $member->role);
        $this->assertAuthenticatedAs($member);
        $this->assertSame(0, Invite::count());
        $this->assertSame(['invite.created', 'member.joined'], AuditEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_a_used_invite_gives_404(): void
    {
        $link = $this->createInviteLink(Role::Developer);
        $this->post($link, self::NEW_MEMBER)->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->get($link)->assertNotFound();
        $this->post($link, [...self::NEW_MEMBER, 'email' => 'mallory@example.com'])->assertNotFound();

        $this->assertSame(0, User::where('email', 'mallory@example.com')->count());
    }

    public function test_an_expired_invite_gives_404(): void
    {
        $link = $this->createInviteLink(Role::Developer);

        $this->travel(7)->days();
        $this->travel(1)->second();

        $this->get($link)->assertNotFound();
        $this->post($link, self::NEW_MEMBER)->assertNotFound();
        $this->assertSame(0, User::where('email', 'grace@example.com')->count());
    }

    public function test_an_invite_works_until_it_expires(): void
    {
        $link = $this->createInviteLink(Role::Developer);

        $this->travel(7)->days();
        $this->travel(-1)->second();

        $this->get($link)->assertOk();
    }

    public function test_a_revoked_invite_gives_404(): void
    {
        $owner = User::factory()->owner()->create();
        $link = $this->actingAs($owner)->post('/settings/invites', ['role' => 'developer'])->getSession()->get('invite_link');

        $this->actingAs($owner)->delete('/settings/invites/'.Invite::sole()->id)->assertRedirect('/settings/members');
        $this->post('/logout');

        $this->get($link)->assertNotFound();
    }

    public function test_a_wrong_token_gives_404(): void
    {
        Invite::factory()->create();

        $this->get('/invites/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_a_failed_accept_keeps_the_invite(): void
    {
        $link = $this->createInviteLink(Role::Developer);
        $taken = User::factory()->create()->email;

        $this->post($link, [...self::NEW_MEMBER, 'email' => $taken])->assertSessionHasErrors('email');

        $this->assertSame(1, Invite::count());
    }
}
