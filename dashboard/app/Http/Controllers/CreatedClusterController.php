<?php

namespace App\Http\Controllers;

use App\Actions\CreateCluster;
use App\Actions\DestroyCluster;
use App\Clusters\DigitalOceanCluster;
use App\Http\Requests\CreateClusterRequest;
use App\Models\Cluster;
use App\Models\ProviderCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clusters that OpenTofu creates on DigitalOcean with the stored key. The key never goes to the browser.
 */
class CreatedClusterController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', Cluster::class);

        $choices = fn (array $table) => collect($table)->map(fn (string $label, string $slug) => ['value' => $slug, 'label' => "{$label} ({$slug})"])->values();

        return Inertia::render('clusters/create-digitalocean', [
            'regions' => $choices(DigitalOceanCluster::REGIONS),
            'node_sizes' => $choices(DigitalOceanCluster::NODE_SIZES),
            'max_nodes' => DigitalOceanCluster::MAX_NODES,
            'pricing_url' => DigitalOceanCluster::PRICING_URL,
            'has_key' => ProviderCredential::query()->where('provider', 'digitalocean')->exists(),
        ]);
    }

    public function store(CreateClusterRequest $request, CreateCluster $createCluster): RedirectResponse
    {
        $cluster = $createCluster->handle($request->user(), $request->cluster());

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
