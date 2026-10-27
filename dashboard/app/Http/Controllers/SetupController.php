<?php

namespace App\Http\Controllers;

use App\Domains\Members\Actions\CreateOwner;
use App\Domains\Members\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(string $token): Response
    {
        $this->ensureSetupIsOpen($token);

        return Inertia::render('setup', ['token' => $token]);
    }

    public function store(Request $request, string $token, CreateOwner $createOwner): RedirectResponse
    {
        $this->ensureSetupIsOpen($token);

        $owner = $createOwner->execute($request->validate(User::newAccountRules()));

        Auth::login($owner);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    /**
     * A 404 instead of a 403 gives a scanner no sign that setup exists.
     */
    private function ensureSetupIsOpen(string $token): void
    {
        $expected = (string) config('opencloud.setup_token');

        abort_unless($expected !== '' && hash_equals($expected, $token) && ! User::query()->exists(), 404);
    }
}
