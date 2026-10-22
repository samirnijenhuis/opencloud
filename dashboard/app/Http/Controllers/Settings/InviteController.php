<?php

namespace App\Http\Controllers\Settings;

use App\Actions\IssueInvite;
use App\Actions\RevokeInvite;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InviteController extends Controller
{
    public function store(Request $request, IssueInvite $issueInvite): RedirectResponse
    {
        Gate::authorize('invite', User::class);

        $validated = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);

        [, $token] = $issueInvite->handle($request->user(), Role::from($validated['role']));

        return to_route('members.index')->with('invite_link', route('invites.show', $token));
    }

    public function destroy(Request $request, Invite $invite, RevokeInvite $revokeInvite): RedirectResponse
    {
        Gate::authorize('invite', User::class);

        $revokeInvite->handle($invite, $request->user());

        return to_route('members.index');
    }
}
