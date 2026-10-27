<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\DeleteEnvVar;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Http\Requests\EnvVarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class EnvVarController extends Controller
{
    /** The settings page shows the values now. Old links still work. */
    public function index(Environment $environment): RedirectResponse
    {
        return redirect(route('environments.settings', $environment).'#environment-values');
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
