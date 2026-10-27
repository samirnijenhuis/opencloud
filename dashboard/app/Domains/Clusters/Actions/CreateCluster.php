<?php

namespace App\Domains\Clusters\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\ClusterProvider;
use App\Domains\Clusters\ClusterProviders;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Jobs\ProvisionCluster;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Members\Models\User;
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
    public function execute(User $by, ClusterProvider $provider, array $data): Cluster
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
