<?php

namespace App\Http\Controllers\Settings;

use App\Domains\CloudProviders\Actions\DeleteProviderCredential;
use App\Domains\CloudProviders\Actions\StoreProviderCredential;
use App\Domains\CloudProviders\CheckResult;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\CloudProviders\ProviderGuide;
use App\Domains\CloudProviders\Providers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProviderKeyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProviderCredential::class);

        $credentials = ProviderCredential::query()->get()->keyBy('provider');
        $result = $request->session()->get('provider_check');

        return Inertia::render('settings/provider-keys', [
            'providers' => collect(Providers::all())->map(fn (ProviderGuide $guide) => [
                'key' => $guide->key,
                'label' => $guide->label,
                'use' => $guide->use,
                'url' => $guide->url,
                'steps' => $guide->steps,
                'rights' => array_map(fn ($right) => ['label' => $right->label, 'optional' => $right->optional], $guide->rights),
                'command' => $guide->command,
                'paste_hint' => $guide->pasteHint,
                'stored' => ($credential = $credentials->get($guide->key)) === null ? null : [
                    'id' => $credential->id,
                    'checked_at' => $credential->checked_at?->toIso8601String(),
                    'missing_rights' => $guide->labelsOf($credential->missing_rights ?? []),
                ],
                'result' => $result !== null && $result['provider'] === $guide->key ? $result : null,
            ])->values(),
        ]);
    }

    public function update(Request $request, string $provider, StoreProviderCredential $store): RedirectResponse
    {
        Gate::authorize('create', ProviderCredential::class);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
        ]);
        abort_unless(in_array($provider, Providers::keys(), true), 404);

        $result = $store->handle($request->user(), $provider, $validated['token']);

        return $this->back($provider, $result);
    }

    public function recheck(Request $request, ProviderCredential $credential, StoreProviderCredential $store): RedirectResponse
    {
        Gate::authorize('update', $credential);

        $result = $store->handle($request->user(), $credential->provider, $credential->token);

        return $this->back($credential->provider, $result);
    }

    public function destroy(Request $request, ProviderCredential $credential, DeleteProviderCredential $delete): RedirectResponse
    {
        Gate::authorize('delete', $credential);

        $delete->handle($credential, $request->user());

        return to_route('provider-keys.index');
    }

    private function back(string $provider, CheckResult $result): RedirectResponse
    {
        $guide = Providers::find($provider);

        return to_route('provider-keys.index')->with('provider_check', [
            'provider' => $provider,
            'status' => $result->status->value,
            'saved' => $guide->accepts($result),
            'message' => $result->message,
            'missing' => $guide->labelsOf($result->missing),
        ]);
    }
}
