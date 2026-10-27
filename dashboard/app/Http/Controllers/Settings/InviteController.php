<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Members\Actions\IssueInvite;
use App\Domains\Members\Actions\RevokeInvite;
use App\Domains\Members\Enums\Role;
use App\Domains\Members\Models\Invite;
use App\Domains\Members\Models\User;
use App\Http\Controllers\Controller;
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

        [, $token] = $issueInvite->execute($request->user(), Role::from($validated['role']));

        return to_route('members.index')->with('invite_link', route('invites.show', $token));
    }

    public function destroy(Request $request, Invite $invite, RevokeInvite $revokeInvite): RedirectResponse
    {
        Gate::authorize('invite', User::class);

        $revokeInvite->execute($invite, $request->user());

        return to_route('members.index');
    }
}
