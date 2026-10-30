<?php

namespace App\Http\Controllers;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Exceptions\DeployKeyRefused;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\RepositoryName;
use App\Domains\Routing\Models\Domain;
use App\Http\Requests\StoreApplicationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $applications = Application::query()->with('cluster:id,name')->orderBy('name')->get();
        $productions = Environment::query()
            ->whereIn('application_id', $applications->modelKeys())
            ->where('name', 'production')
            ->get()
            ->keyBy('application_id');

        $latest = Deployment::query()
            ->whereIn('id', Deployment::query()->selectRaw('max(id)')->whereIn('environment_id', $productions->modelKeys())->groupBy('environment_id'))
            ->get()
            ->keyBy('environment_id');

        $domains = Domain::query()
            ->whereIn('environment_id', $productions->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('environment_id');

        return Inertia::render('applications/index', [
            'applications' => $applications->map(function (Application $application) use ($productions, $latest, $domains) {
                $production = $productions->get($application->id);
                $deployment = $production ? $latest->get($production->id) : null;
                $hostname = $production ? $domains->get($production->id)?->first()?->hostname : null;

                return [
                    'id' => $application->id,
                    'name' => $application->name,
                    'repository' => $application->repository,
                    'production_id' => $production?->id,
                    'status' => $deployment?->status,
                    'url' => $hostname ? "https://{$hostname}" : null,
                    'deployed_at' => $deployment?->created_at->toIso8601String(),
                ];
            })->values(),
            'canCreate' => $request->user()->can('create', Application::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Application::class);

        $clusters = Cluster::query()->orderBy('id')->get(['id', 'name', 'kind', 'ingress_ip']);

        return Inertia::render('applications/create', [
            'clusters' => $clusters,
            'defaultClusterId' => ($clusters->firstWhere('kind', ClusterKind::LOCAL) ?? $clusters->first())?->id,
        ]);
    }

    public function store(StoreApplicationRequest $request, CreateApplication $createApplication): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        try {
            $application = $createApplication->execute($user, [
                'name' => $data['name'] ?? basename(preg_replace('/\.git$/', '', $data['repository'])),
                'repository' => $data['repository'],
                'cluster_id' => $data['cluster_id'],
                'type' => ApplicationType::from($data['type']),
                'port' => $data['port'] ?? null,
                'branch' => $data['branch'] ?? null,
                'deploy_key' => $request->boolean('deploy_key'),
                'deploy_private_key' => $data['deploy_private_key'] ?? null,
            ]);
        } catch (DeployKeyRefused) {
            return back()->withInput($request->except('deploy_private_key'))->withErrors(['deploy_private_key' => DeployKeyRefused::STEP]);
        } catch (RuntimeException) {
            return back()->withInput($request->except('deploy_private_key'))->withErrors(['repository' => 'We could not find this repository and branch. Check the address and the branch name.']);
        }

        // The user adds the new key on GitHub first, so the page that shows the key and the steps comes before the environment.
        if ($request->boolean('deploy_key')) {
            return to_route('applications.show', $application);
        }

        $production = $application->environments()->where('name', 'production')->firstOrFail();

        return to_route('environments.show', $production);
    }

    public function show(Request $request, Application $application): Response
    {
        Gate::authorize('view', $application);

        $environments = $application->environments()
            ->with(['domains' => fn ($query) => $query->orderBy('id')])
            ->get();

        $latest = Deployment::query()
            ->whereIn('id', Deployment::query()->selectRaw('max(id)')->whereIn('environment_id', $environments->modelKeys())->groupBy('environment_id'))
            ->get()
            ->keyBy('environment_id');

        $githubRepository = RepositoryName::parse($application->repository);

        return Inertia::render('applications/show', [
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'repository' => $application->repository,
                'type' => $application->type,
                'port' => $application->port,
            ],
            'previews' => [
                'previews_enabled' => $application->previews_enabled,
                'max_previews' => config('opencloud.max_previews'),
            ],
            'deployPublicKey' => $application->deploy_public_key,
            'canUpdate' => $request->user()->can('update', $application),
            'environments' => $environments
                ->sortBy(fn (Environment $environment) => [match (true) {
                    $environment->name === 'production' => 0,
                    $environment->kind === EnvironmentKind::PERMANENT => 1,
                    $environment->kind === EnvironmentKind::PREVIEW_TEMPLATE => 2,
                    default => 3,
                }, $environment->name])
                ->map(fn (Environment $environment) => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'kind' => $environment->kind,
                    'is_protected' => $environment->is_protected,
                    'branch' => $environment->branch,
                    'pr_number' => $environment->pr_number,
                    'pr_url' => $githubRepository && $environment->pr_number ? "https://github.com/{$githubRepository}/pull/{$environment->pr_number}" : null,
                    'status' => $latest->get($environment->id)?->status,
                    'url' => ($hostname = $environment->domains->first()?->hostname) ? "https://{$hostname}" : null,
                ])
                ->values(),
        ]);
    }
}
