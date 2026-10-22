<?php

namespace App\Http\Controllers;

use App\Actions\ReadLogs;
use App\Enums\Ability;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Kube\KubectlException;
use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentLogController extends Controller
{
    use PresentsEnvironment;

    public function show(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);
        $this->authorizeLogs($request);

        return Inertia::render('environments/logs', [
            ...$this->environmentProps($environment, $request->user()),
            'processes' => array_values(array_filter([
                'web',
                $environment->worker_enabled ? 'worker' : null,
                $environment->scheduler_enabled ? 'scheduler' : null,
            ])),
        ]);
    }

    public function read(Request $request, Environment $environment, ReadLogs $readLogs): JsonResponse
    {
        Gate::authorize('view', $environment);
        $this->authorizeLogs($request);

        $validated = $request->validate(['process' => ['required', 'in:web,worker,scheduler']]);

        try {
            return response()->json(['log' => $readLogs->handle($environment, $validated['process']), 'error' => null]);
        } catch (KubectlException) {
            return response()->json(['log' => '', 'error' => 'The logs are not available yet. Deploy the environment first.']);
        }
    }

    private function authorizeLogs(Request $request): void
    {
        abort_unless($request->user()->role->allows(Ability::ReadLogs), 403);
    }
}
