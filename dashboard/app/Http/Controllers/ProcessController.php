<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\AddProcess;
use App\Domains\Environments\Actions\RemoveProcess;
use App\Domains\Environments\Actions\UpdateProcess;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProcessController extends Controller
{
    public function store(Request $request, Environment $environment, AddProcess $addProcess): RedirectResponse
    {
        Gate::authorize('update', $environment);

        $data = $this->validated($request);
        $addProcess->execute($environment, $request->user(), $data['name'], $data['command'], (int) $data['instances'], (bool) $data['exclusive']);

        return back();
    }

    public function update(Request $request, Process $process, UpdateProcess $updateProcess): RedirectResponse
    {
        Gate::authorize('update', $process->environment);

        $data = $this->validated($request);
        $updateProcess->execute($process, $request->user(), $data['name'], $data['command'], (int) $data['instances'], (bool) $data['exclusive']);

        return back();
    }

    public function destroy(Request $request, Process $process, RemoveProcess $removeProcess): RedirectResponse
    {
        Gate::authorize('update', $process->environment);

        $removeProcess->execute($process, $request->user());

        return back();
    }

    /** @return array{name: string, command: string, instances: int|string, exclusive: bool|string} */
    private function validated(Request $request): array
    {
        $exclusive = $request->boolean('exclusive');

        return $request->validate(Process::rules($exclusive), Process::messages($exclusive));
    }
}
