<?php

namespace App\Http\Controllers;

use App\Actions\UpdateEnvironmentSettings;
use App\Enums\Size;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\UpdateEnvironmentSettingsRequest;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EnvironmentController extends Controller
{
    use PresentsEnvironment;

    public function show(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        $latest = $environment->deployments()->latest('id')->with('triggeredBy:id,name')->first();

        return Inertia::render('environments/show', [
            ...$this->environmentProps($environment, $request->user()),
            'latestDeployment' => $latest ? $this->deploymentProps($latest) : null,
        ]);
    }

    public function settings(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        return Inertia::render('environments/settings', [
            ...$this->environmentProps($environment, $request->user()),
            'settings' => [
                'branch' => $environment->branch,
                'auto_deploy' => $environment->auto_deploy,
                'size' => $environment->size,
                'min_instances' => $environment->min_instances,
                'max_instances' => $environment->max_instances,
                'cpu_target' => $environment->cpu_target,
                'health_path' => $environment->health_path,
                'build_command' => $environment->build_command,
                'deploy_commands' => $environment->deploy_commands,
                'worker_enabled' => $environment->worker_enabled,
                'scheduler_enabled' => $environment->scheduler_enabled,
            ],
            'sizes' => array_map(fn (Size $size) => [
                'value' => $size->value,
                'cpu_millicores' => $size->cpuMillicores(),
                'memory_mib' => $size->memoryMib(),
            ], Size::cases()),
        ]);
    }

    public function update(UpdateEnvironmentSettingsRequest $request, Environment $environment, UpdateEnvironmentSettings $updateSettings): RedirectResponse
    {
        try {
            $updateSettings->handle($environment, $request->user(), $request->settings());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['max_instances' => $e->getMessage()]);
        }

        return back();
    }
}
