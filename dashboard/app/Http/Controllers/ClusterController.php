<?php

namespace App\Http\Controllers;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Actions\ConnectCluster;
use App\Domains\Clusters\Actions\DisconnectCluster;
use App\Domains\Clusters\Actions\ReinstallClusterParts;
use App\Domains\Clusters\ClusterProvider;
use App\Domains\Clusters\ClusterProviders;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Models\Datastore;
use App\Http\Requests\ConnectClusterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The props name each field on purpose. The kubeconfig and the registry password never go to the browser.
 */
class ClusterController extends Controller
{
    public function index(Request $request, ClusterProviders $providers): Response
    {
        return Inertia::render('clusters/index', [
            'clusters' => Cluster::query()->orderBy('id')->get(['id', 'name', 'kind', 'ingress_ip', 'status', 'parts_version']),
            'providers' => $providers->all()->map(fn (ClusterProvider $provider) => ['key' => $provider->key(), 'label' => $provider->label()])->values(),
            'canConnect' => $request->user()->can('create', Cluster::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Cluster::class);

        return Inertia::render('clusters/create');
    }

    public function store(ConnectClusterRequest $request, ConnectCluster $connectCluster): RedirectResponse
    {
        $cluster = $connectCluster->execute($request->user(), [...$request->validated(), 'registry_insecure' => $request->boolean('registry_insecure')]);

        return to_route('clusters.show', $cluster);
    }

    public function show(Request $request, Cluster $cluster, ClusterProviders $providers): Response
    {
        Gate::authorize('view', $cluster);

        $user = $request->user();
        $local = $cluster->kind === ClusterKind::Local;

        return Inertia::render('clusters/show', [
            'cluster' => [
                'id' => $cluster->id,
                'name' => $cluster->name,
                'kind' => $cluster->kind,
                'status' => $cluster->status,
                'error' => $cluster->error,
                'ingress_ip' => $cluster->ingress_ip,
                'parts_version' => $cluster->parts_version,
                'registry_url' => $cluster->registry_url,
                'registry_insecure' => $cluster->registry_insecure,
            ],
            'applications' => $cluster->applications()->orderBy('name')->get()
                ->map(fn (Application $application) => ['id' => $application->id, 'name' => $application->name, 'repository' => $application->repository])
                ->values(),
            'datastores' => $cluster->datastores()->orderBy('id')->get()
                ->map(fn (Datastore $datastore) => ['id' => $datastore->id, 'kind' => $datastore->kind])
                ->values(),
            'can' => [
                'reinstall' => ! $local && $user->can('update', $cluster),
                'disconnect' => ! $local && $user->can('delete', $cluster),
            ],
            'created' => $cluster->kind === ClusterKind::Created ? [
                'provider' => $providers->find((string) $cluster->provider)?->label() ?? (string) $cluster->provider,
                'region' => $cluster->region,
                'node_size' => $cluster->node_size,
                'min_nodes' => $cluster->min_nodes,
                'max_nodes' => $cluster->max_nodes,
                'tofu_log' => $user->can('update', $cluster) ? $cluster->tofu_log : null,
                'can_destroy' => $user->can('delete', $cluster),
            ] : null,
        ]);
    }

    public function reinstall(Request $request, Cluster $cluster, ReinstallClusterParts $reinstallClusterParts): RedirectResponse
    {
        Gate::authorize('update', $cluster);

        $reinstallClusterParts->execute($cluster, $request->user());

        return to_route('clusters.show', $cluster);
    }

    public function destroy(Request $request, Cluster $cluster, DisconnectCluster $disconnectCluster): RedirectResponse
    {
        Gate::authorize('delete', $cluster);

        $disconnectCluster->execute($cluster, $request->user(), $request->boolean('remove_parts'));

        return to_route('clusters.index');
    }
}
