<?php

namespace App\Http\Controllers;

use App\Actions\DeleteEnvVar;
use App\Actions\SetEnvVar;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\EnvVarRequest;
use App\Models\Environment;
use App\Models\EnvVar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EnvVarController extends Controller
{
    use PresentsEnvironment;

    public function index(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        return Inertia::render('environments/env', [
            ...$this->environmentProps($environment, $request->user()),
            // Only a plain user value goes to the browser. A secret and a datastore value never do.
            'envVars' => $environment->envVars()->orderBy('key')->get()->map(fn (EnvVar $envVar) => [
                'id' => $envVar->id,
                'key' => $envVar->key,
                'source' => $envVar->source,
                'is_secret' => $envVar->is_secret,
                'available_at' => $envVar->available_at,
                'value' => $envVar->source === EnvVarSource::User && ! $envVar->is_secret ? $envVar->value : null,
            ]),
        ]);
    }

    public function store(EnvVarRequest $request, Environment $environment, SetEnvVar $setEnvVar): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $setEnvVar->handle(
                $environment,
                $request->user(),
                $validated['key'],
                $validated['value'] ?? null,
                $validated['is_secret'] ?? false,
                EnvVarAvailability::from($validated['available_at']),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['value' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, EnvVar $envVar, DeleteEnvVar $deleteEnvVar): RedirectResponse
    {
        Gate::authorize('update', $envVar->environment);

        try {
            $deleteEnvVar->handle($envVar, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['key' => $e->getMessage()]);
        }

        return back();
    }
}
