<?php

namespace App\Http\Controllers;

use App\Domains\Deployments\Actions\StartDeployment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;
use App\Http\Controllers\Concerns\PresentsEnvironment;
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
            'waiting_for' => $this->waitingFor($deployment),
        ]);
    }

    /**
     * One builder runs one deployment at a time, so a queued deployment waits for the oldest running one.
     *
     * @return array{id: int, application: string, environment: string, queued_before: int}|null
     */
    private function waitingFor(Deployment $deployment): ?array
    {
        if ($deployment->status !== DeploymentStatus::QUEUED) {
            return null;
        }

        $running = Deployment::query()
            ->whereIn('status', [DeploymentStatus::BUILDING, DeploymentStatus::DEPLOYING])
            ->where('id', '<', $deployment->id)
            ->with('environment.application')
            ->orderBy('id')
            ->first();

        if ($running === null) {
            return null;
        }

        return [
            'id' => $running->id,
            'application' => $running->environment->application->name,
            'environment' => $running->environment->name,
            'queued_before' => Deployment::query()->where('status', DeploymentStatus::QUEUED)->where('id', '<', $deployment->id)->count(),
        ];
    }

    public function store(Request $request, Environment $environment, StartDeployment $startDeployment): RedirectResponse
    {
        Gate::authorize('deploy', $environment);

        try {
            $deployment = $startDeployment->execute($environment, $request->user());
        } catch (InvalidArgumentException|RuntimeException) {
            return back()->withErrors(['deploy' => "We could not read the branch {$environment->branch}. Check the branch name in the settings."]);
        }

        return to_route('deployments.show', $deployment);
    }
}
