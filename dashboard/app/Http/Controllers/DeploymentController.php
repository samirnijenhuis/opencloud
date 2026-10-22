<?php

namespace App\Http\Controllers;

use App\Actions\StartDeployment;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Models\Deployment;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class DeploymentController extends Controller
{
    use PresentsEnvironment;

    public function index(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        return Inertia::render('environments/deployments', [
            ...$this->environmentProps($environment, $request->user()),
            'deployments' => $environment->deployments()
                ->with('triggeredBy:id,name')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (Deployment $deployment) => $this->deploymentProps($deployment)),
        ]);
    }

    public function show(Request $request, Deployment $deployment): Response
    {
        $environment = $deployment->environment;
        Gate::authorize('view', $environment);

        return Inertia::render('deployments/show', [
            ...$this->environmentProps($environment, $request->user()),
            'deployment' => [
                ...$this->deploymentProps($deployment),
                'log' => (string) $deployment->log,
            ],
        ]);
    }

    public function store(Request $request, Environment $environment, StartDeployment $startDeployment): RedirectResponse
    {
        Gate::authorize('deploy', $environment);

        try {
            $deployment = $startDeployment->handle($environment, $request->user());
        } catch (InvalidArgumentException|RuntimeException) {
            return back()->withErrors(['deploy' => "We could not read the branch {$environment->branch}. Check the branch name in the settings."]);
        }

        return to_route('deployments.show', $deployment);
    }
}
