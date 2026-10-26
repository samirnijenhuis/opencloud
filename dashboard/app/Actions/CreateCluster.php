<?php

namespace App\Actions;

use App\Clusters\ClusterProvider;
use App\Clusters\ClusterProviders;
use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Jobs\ProvisionCluster;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateCluster
{
    public function __construct(private readonly ClusterProviders $providers) {}

    /**
     * Stores a created cluster and starts ProvisionCluster, which creates it on the provider with OpenTofu.
     *
     * @param  array{name: string, region: string, node_size: string, min_nodes: int, max_nodes: int}  $data
     *
     * @throws ValidationException when there is no key for the provider
     */
    public function handle(User $by, ClusterProvider $provider, array $data): Cluster
    {
        if (! $this->providers->hasCredential($provider)) {
            throw ValidationException::withMessages(['name' => "Add a {$provider->label()} key in Settings, Provider keys, first."]);
        }

        $cluster = Cluster::query()->create([...$data, 'kind' => ClusterKind::Created, 'provider' => $provider->key(), 'status' => ClusterStatus::Provisioning]);

        AuditEvent::record($by, 'cluster.created', $cluster, $data);
        ProvisionCluster::dispatch($cluster);

        return $cluster;
    }
}
