<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\ReadLogs;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Ability;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\ReadLogsRequest;
use App\Support\Kube\KubectlException;
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
            'processes' => $environment->processNames(),
        ]);
    }

    public function read(ReadLogsRequest $request, Environment $environment, ReadLogs $readLogs): JsonResponse
    {
        try {
            return response()->json([
                ...$readLogs->execute($environment, $request->validated('process'), $request->date('from'), $request->date('to'), $request->validated('search')),
                'error' => null,
            ]);
        } catch (KubectlException) {
            return response()->json(['lines' => [], 'truncated' => false, 'error' => 'The log store of the cluster does not answer. Try again in a minute.']);
        }
    }

    private function authorizeLogs(Request $request): void
    {
        abort_unless($request->user()->role->allows(Ability::READ_LOGS), 403);
    }
}
