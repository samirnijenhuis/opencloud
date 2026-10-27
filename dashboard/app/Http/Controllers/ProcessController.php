<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\AddProcess;
use App\Domains\Environments\Actions\RemoveProcess;
use App\Domains\Environments\Actions\UpdateProcess;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Http\Requests\AddProcessRequest;
use App\Http\Requests\UpdateProcessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProcessController extends Controller
{
    public function store(AddProcessRequest $request, Environment $environment, AddProcess $addProcess): RedirectResponse
    {
        $data = $request->validated();
        $addProcess->execute(
            $environment,
            $request->user(),
            $data['name'],
            $data['command'],
            $request->enum('kind', ProcessKind::class),
            $data['schedule'] ?? null,
            $request->enum('size', Size::class),
            $data['min_instances'] ?? null,
            $data['max_instances'] ?? null,
            $data['cpu_target'] ?? null,
        );

        return back();
    }

    public function update(UpdateProcessRequest $request, Process $process, UpdateProcess $updateProcess): RedirectResponse
    {
        $data = $request->validated();
        $updateProcess->execute(
            $process,
            $request->user(),
            $data['name'],
            $data['command'],
            $request->enum('kind', ProcessKind::class),
            $data['schedule'] ?? null,
            $request->enum('size', Size::class),
            $data['min_instances'] ?? null,
            $data['max_instances'] ?? null,
            $data['cpu_target'] ?? null,
        );

        return back();
    }

    public function destroy(Request $request, Process $process, RemoveProcess $removeProcess): RedirectResponse
    {
        Gate::authorize('update', $process->environment);

        $removeProcess->execute($process, $request->user());

        return back();
    }
}
