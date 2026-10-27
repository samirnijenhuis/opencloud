<?php

namespace App\Domains\Clusters\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Jobs\TearDownCluster;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class DestroyCluster
{
    /**
     * Starts the OpenTofu destroy of a cluster that opencloud created. TearDownCluster removes the record after the destroy.
     *
     * @throws ValidationException for a cluster that opencloud did not create, during a run, while applications use the
     *                             cluster, or while it has managed databases
     */
    public function execute(Cluster $cluster, User $by): void
    {
        if ($cluster->kind !== ClusterKind::CREATED) {
            throw ValidationException::withMessages(['cluster' => 'Opencloud did not create this cluster, so it does not destroy it. Disconnect it instead.']);
        }

        if (in_array($cluster->status, [ClusterStatus::PROVISIONING, ClusterStatus::DESTROYING], true)) {
            throw ValidationException::withMessages(['cluster' => 'OpenTofu still runs for this cluster. Wait until it ends.']);
        }

        $applications = $cluster->applications()->orderBy('name')->pluck('name');

        if ($applications->isNotEmpty()) {
            throw ValidationException::withMessages(['cluster' => 'These applications use this cluster: '.$applications->implode(', ').'. Remove them first.']);
        }

        if ($cluster->datastores()->where('source', DatastoreSource::MANAGED)->exists()) {
            throw ValidationException::withMessages(['cluster' => 'This cluster has managed resources. Detach them first.']);
        }

        $cluster->update(['status' => ClusterStatus::DESTROYING, 'error' => null]);

        AuditEvent::record($by, 'cluster.destroy_requested', $cluster, ['name' => $cluster->name]);
        TearDownCluster::dispatch($cluster);
    }
}
