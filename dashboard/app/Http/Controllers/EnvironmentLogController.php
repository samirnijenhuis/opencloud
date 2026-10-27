<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\ReadLogs;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Ability;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Support\Kube\KubectlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'processes' => $environment->processNames(),
        ]);
    }

    public function read(Request $request, Environment $environment, ReadLogs $readLogs): JsonResponse
    {
        Gate::authorize('view', $environment);
        $this->authorizeLogs($request);

        $validated = $request->validate(['process' => ['required', 'string', Rule::in($environment->processNames())]]);

        try {
            return response()->json(['log' => $readLogs->execute($environment, $validated['process']), 'error' => null]);
        } catch (KubectlException) {
            return response()->json(['log' => '', 'error' => 'The logs are not available yet. Deploy the environment first.']);
        }
    }

    private function authorizeLogs(Request $request): void
    {
        abort_unless($request->user()->role->allows(Ability::READ_LOGS), 403);
    }
}
