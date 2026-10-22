<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ChangeMemberRole;
use App\Actions\RemoveMember;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();
        $canInvite = $actor->can('invite', User::class);

        return Inertia::render('settings/members', [
            'members' => User::query()->orderBy('id')->get()->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role,
                'can_manage' => $actor->can('update', $member),
            ]),
            'roles' => array_column(Role::cases(), 'value'),
            'canInvite' => $canInvite,
            'invites' => $canInvite
                ? Invite::query()->where('expires_at', '>', now())->orderBy('id')->get(['id', 'role', 'expires_at'])
                : [],
            'inviteLink' => $request->session()->get('invite_link'),
        ]);
    }

    public function update(Request $request, User $user, ChangeMemberRole $changeMemberRole): RedirectResponse
    {
        Gate::authorize('update', $user);

        $validated = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);

        $changeMemberRole->handle($user, $request->user(), Role::from($validated['role']));

        return to_route('members.index');
    }

    public function destroy(Request $request, User $user, RemoveMember $removeMember): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $removeMember->handle($user, $request->user());

        return to_route('members.index');
    }
}
