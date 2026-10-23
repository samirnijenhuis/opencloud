<?php

namespace App\Actions;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Jobs\ProvisionCluster;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateCluster
{
    /**
     * Stores a created cluster and starts ProvisionCluster, which creates it on DigitalOcean with OpenTofu.
     *
     * @param  array{name: string, region: string, node_size: string, min_nodes: int, max_nodes: int}  $data
     *
     * @throws ValidationException when there is no DigitalOcean key
     */
    public function handle(User $by, array $data): Cluster
    {
        if (! ProviderCredential::query()->where('provider', 'digitalocean')->exists()) {
            throw ValidationException::withMessages(['name' => 'Add a DigitalOcean key in Settings, Provider keys, first.']);
        }

        $cluster = Cluster::query()->create([...$data, 'kind' => ClusterKind::Created, 'provider' => 'digitalocean', 'status' => ClusterStatus::Provisioning]);

        AuditEvent::record($by, 'cluster.created', $cluster, $data);
        ProvisionCluster::dispatch($cluster);

        return $cluster;
    }
}
