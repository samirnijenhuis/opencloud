<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\RunCommand;
use App\Domains\Environments\Models\CommandRun;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Ability;
use App\Support\SecretMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The output of a command is like a log, so reading it needs the same rights as the logs. */
class CommandRunController extends Controller
{
    private const HISTORY = 20;

    public function index(Request $request, Environment $environment): JsonResponse
    {
        $this->authorizeRead($request, $environment);

        $masker = SecretMasker::forEnvironment($environment);
        $runs = $environment->commandRuns()->with(['user:id,name', 'deployment:id,commit_sha'])->latest('id')->limit(self::HISTORY)->get();

        return response()->json(['runs' => $runs->map(fn (CommandRun $run) => $this->present($run, $masker))->all()]);
    }

    public function show(Request $request, CommandRun $commandRun): JsonResponse
    {
        $this->authorizeRead($request, $commandRun->environment);

        $masker = SecretMasker::forEnvironment($commandRun->environment);

        return response()->json(['run' => [...$this->present($commandRun, $masker), 'output' => $commandRun->output ?? '']]);
    }

    /** A JSON client gets the new run, so it can poll it. An Inertia form goes back. */
    public function store(Request $request, Environment $environment, RunCommand $runCommand): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $environment);

        $validated = $request->validate(['command' => ['required', 'string', 'max:2000']]);
        $run = $runCommand->execute($environment, $request->user(), $validated['command']);

        if ($request->wantsJson()) {
            return response()->json(['run' => $this->present($run->fresh(['user', 'deployment']), SecretMasker::forEnvironment($environment))], 201);
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function present(CommandRun $run, SecretMasker $masker): array
    {
        return [
            'id' => $run->id,
            'command' => $masker->mask($run->command),
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'user' => $run->user?->name,
            'commit_sha' => $run->deployment?->commit_sha,
            'created_at' => $run->created_at->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function authorizeRead(Request $request, Environment $environment): void
    {
        Gate::authorize('view', $environment);
        abort_unless($request->user()->role->allows(Ability::ReadLogs), 403);
    }
}
