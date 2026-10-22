<?php

namespace App\Http\Controllers;

use App\Actions\AcceptInvite;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InviteAcceptController extends Controller
{
    public function show(string $token): Response
    {
        $invite = Invite::findValid($token) ?? abort(404);

        return Inertia::render('invites/accept', [
            'token' => $token,
            'role' => $invite->role,
        ]);
    }

    public function store(Request $request, string $token, AcceptInvite $acceptInvite): RedirectResponse
    {
        $invite = Invite::findValid($token) ?? abort(404);

        $member = $acceptInvite->handle($invite, $request->validate(User::newAccountRules()));

        Auth::login($member);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
