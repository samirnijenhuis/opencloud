<?php

namespace App\Actions;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Enums\DatastoreSource;
use App\Jobs\TearDownCluster;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DestroyCluster
{
    /**
     * Starts the OpenTofu destroy of a cluster that opencloud created. TearDownCluster removes the record after the destroy.
     *
     * @throws ValidationException for a cluster that opencloud did not create, during a run, while applications use the
     *                             cluster, or while it has managed databases
     */
    public function handle(Cluster $cluster, User $by): void
    {
        if ($cluster->kind !== ClusterKind::Created) {
            throw ValidationException::withMessages(['cluster' => 'Opencloud did not create this cluster, so it does not destroy it. Disconnect it instead.']);
        }

        if (in_array($cluster->status, [ClusterStatus::Provisioning, ClusterStatus::Destroying], true)) {
            throw ValidationException::withMessages(['cluster' => 'OpenTofu still runs for this cluster. Wait until it ends.']);
        }

        $applications = $cluster->applications()->orderBy('name')->pluck('name');

        if ($applications->isNotEmpty()) {
            throw ValidationException::withMessages(['cluster' => 'These applications use this cluster: '.$applications->implode(', ').'. Remove them first.']);
        }

        if ($cluster->datastores()->where('source', DatastoreSource::Managed)->exists()) {
            throw ValidationException::withMessages(['cluster' => 'This cluster has managed databases. Detach them first.']);
        }

        $cluster->update(['status' => ClusterStatus::Destroying, 'error' => null]);

        AuditEvent::record($by, 'cluster.destroy_requested', $cluster, ['name' => $cluster->name]);
        TearDownCluster::dispatch($cluster);
    }
}
