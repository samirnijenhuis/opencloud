<?php

namespace App\Http\Controllers;

use App\Actions\CreateApplication;
use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\EnvironmentKind;
use App\Enums\Size;
use App\GitHub\RepositoryName;
use App\Http\Requests\StoreApplicationRequest;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Presets\EnvProfileKind;
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
            'defaultClusterId' => ($clusters->firstWhere('kind', ClusterKind::Local) ?? $clusters->first())?->id,
            'sizes' => array_map(fn (Size $size) => [
                'value' => $size->value,
                'cpu_millicores' => $size->cpuMillicores(),
                'memory_mib' => $size->memoryMib(),
            ], Size::cases()),
            'presets' => self::presets(),
        ]);
    }

    public function store(StoreApplicationRequest $request, CreateApplication $createApplication): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        try {
            $application = $createApplication->handle($user, [
                'name' => $data['name'] ?? basename(preg_replace('/\.git$/', '', $data['repository'])),
                'repository' => $data['repository'],
                'cluster_id' => $data['cluster_id'],
                'type' => ApplicationType::from($data['type']),
                'env_profile' => EnvProfileKind::from($data['env_profile']),
                'port' => $data['port'] ?? null,
                'branch' => $data['branch'] ?? null,
                'env' => array_map(fn (array $row) => [
                    'key' => $row['key'],
                    'value' => $row['value'] ?? '',
                    'is_secret' => $row['is_secret'] ?? false,
                    'available_at' => $row['available_at'] ?? 'runtime',
                ], $data['env'] ?? []),
                'domains' => isset($data['domain']) ? [$data['domain']] : [],
                'database' => $data['database'],
                ...(isset($data['processes']) ? ['processes' => array_map(fn (array $row) => [
                    'name' => $row['name'],
                    'command' => $row['command'],
                    'instances' => (int) $row['instances'],
                    'exclusive' => (bool) $row['exclusive'],
                ], $data['processes'])] : []),
                'settings' => [
                    'size' => $data['size'],
                    'min_instances' => $data['min_instances'],
                    'max_instances' => $data['max_instances'],
                    'cpu_target' => $data['cpu_target'],
                    'build_command' => $data['build_command'] ?? null,
                    'deploy_commands' => $data['deploy_commands'] ?? [],
                ],
            ]);
        } catch (RuntimeException) {
            return back()->withInput()->withErrors(['repository' => 'We could not find this repository and branch. Check the address and the branch name.']);
        }

        $production = $application->environments()->where('name', 'production')->firstOrFail();

        return to_route('environments.show', $production);
    }

    public function show(Request $request, Application $application): Response
    {
        Gate::authorize('view', $application);

        $environments = $application->environments()
            ->where('kind', '!=', EnvironmentKind::PreviewTemplate)
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
                'env_profile' => $application->env_profile,
                'port' => $application->port,
            ],
            'previews' => [
                'previews_enabled' => $application->previews_enabled,
                'allow_forks' => $application->allow_forks,
                'preview_domain' => $application->preview_domain,
                'ingress_ip' => $application->cluster->ingress_ip,
                'max_previews' => config('opencloud.max_previews'),
                'template_id' => $application->environments()->where('kind', EnvironmentKind::PreviewTemplate)->value('id'),
            ],
            'canUpdate' => $request->user()->can('update', $application),
            'environments' => $environments
                ->sortBy(fn (Environment $environment) => [$environment->name === 'production' ? 0 : ($environment->kind === EnvironmentKind::Permanent ? 1 : 2), $environment->name])
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

    /** @return list<array<string, mixed>> */
    private static function presets(): array
    {
        return array_map(fn (ApplicationType $type) => [
            'value' => $type->value,
            'port' => $type->defaultPort(),
            'chooses_port' => $type->choosesPort(),
            'uses_build_command' => $type->usesBuildCommand(),
            'deploy_commands' => $type->deployCommands(),
            'processes' => $type->processes(),
            'database' => $type->database(),
            'env_profile' => $type->envProfile(),
        ], ApplicationType::cases());
    }
}
