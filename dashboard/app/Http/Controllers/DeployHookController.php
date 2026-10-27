<?php

namespace App\Http\Controllers;

use App\Domains\Deployments\Actions\DisableDeployHook;
use App\Domains\Deployments\Actions\EnableDeployHook;
use App\Domains\Deployments\Actions\RegenerateDeployHook;
use App\Domains\Deployments\Actions\TriggerDeployHook;
use App\Domains\Deployments\DeployHook;
use App\Domains\Environments\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

class DeployHookController extends Controller
{
    /** The URL is a credential, so only users who may change the environment see it. */
    public function show(Request $request, Environment $environment): JsonResponse
    {
        Gate::authorize('view', $environment);

        return response()->json([
            'enabled' => $environment->deploy_hook_enabled,
            'url' => $request->user()->can('update', $environment) ? DeployHook::url($environment) : null,
        ]);
    }

    public function store(Request $request, Environment $environment, EnableDeployHook $enable): RedirectResponse
    {
        Gate::authorize('update', $environment);

        try {
            $enable->handle($environment, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['deploy_hook' => $e->getMessage()]);
        }

        return back();
    }

    public function regenerate(Request $request, Environment $environment, RegenerateDeployHook $regenerate): RedirectResponse
    {
        Gate::authorize('update', $environment);

        $regenerate->handle($environment, $request->user());

        return back();
    }

    public function destroy(Request $request, Environment $environment, DisableDeployHook $disable): RedirectResponse
    {
        Gate::authorize('update', $environment);

        $disable->handle($environment, $request->user());

        return back();
    }

    /** A wrong token and a disabled hook get the same answer, so the answer tells an attacker nothing. */
    public function trigger(Request $request, string $token, TriggerDeployHook $trigger): JsonResponse
    {
        try {
            $deployment = $trigger->handle($token, $request->ip());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($deployment === null) {
            return response()->json(['message' => 'No deploy hook has this URL.'], 404);
        }

        return response()->json(['deployment_id' => $deployment->id, 'commit_sha' => $deployment->commit_sha, 'status' => $deployment->status], 202);
    }
}
