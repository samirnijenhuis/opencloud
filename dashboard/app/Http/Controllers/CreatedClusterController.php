<?php

namespace App\Http\Controllers;

use App\Domains\Clusters\Actions\CreateCluster;
use App\Domains\Clusters\Actions\DestroyCluster;
use App\Domains\Clusters\ClusterProviders;
use App\Domains\Clusters\Models\Cluster;
use App\Http\Requests\CreateClusterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clusters that OpenTofu creates on a cluster provider with the stored key. The key never goes to the browser.
 */
class CreatedClusterController extends Controller
{
    public function create(string $provider, ClusterProviders $providers): Response
    {
        Gate::authorize('create', Cluster::class);

        $provider = $providers->for($provider);

        $choices = fn (array $table) => collect($table)->map(fn (string $label, string $slug) => ['value' => $slug, 'label' => "{$label} ({$slug})"])->values();

        return Inertia::render('clusters/create-provider', [
            'provider' => ['key' => $provider->key(), 'label' => $provider->label()],
            'regions' => $choices($provider->regions()),
            'node_sizes' => $choices($provider->nodeSizes()),
            'max_nodes' => $provider->maxNodes(),
            'pricing_url' => $provider->pricingUrl(),
            'has_key' => $providers->hasCredential($provider),
        ]);
    }

    public function store(CreateClusterRequest $request, CreateCluster $createCluster): RedirectResponse
    {
        $cluster = $createCluster->handle($request->user(), $request->provider(), $request->cluster());

        return to_route('clusters.show', $cluster);
    }

    public function destroy(Request $request, Cluster $cluster, DestroyCluster $destroyCluster): RedirectResponse
    {
        Gate::authorize('delete', $cluster);

        $request->validate(
            ['confirm' => ['required', 'string', Rule::in([$cluster->name])]],
            ['confirm.required' => 'Type the name of the cluster to confirm.', 'confirm.in' => 'Type the name of the cluster to confirm.'],
        );

        $destroyCluster->handle($cluster, $request->user());

        return to_route('clusters.show', $cluster);
    }
}
