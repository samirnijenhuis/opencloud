<?php

namespace App\Actions;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Jobs\InstallClusterParts;
use App\Jobs\ProvisionCluster;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReinstallClusterParts
{
    /**
     * Applies the parts of this release again. The apply is idempotent, so it repairs a failed or changed install.
     * A null user means opencloud itself, which updates the parts after an upgrade.
     *
     * @throws ValidationException for the local cluster, whose parts the installer manages
     */
    public function handle(Cluster $cluster, ?User $by): void
    {
        if ($cluster->kind === ClusterKind::Local) {
            throw ValidationException::withMessages(['cluster' => 'The installer manages the parts of the local cluster. Run opencloud upgrade on the server.']);
        }

        $cluster->update(['status' => ClusterStatus::Installing, 'error' => null]);

        AuditEvent::record($by, 'cluster.parts_reinstalled', $cluster, ['name' => $cluster->name]);

        // For a created cluster, the OpenTofu apply runs first. It repairs a failed create and changes nothing otherwise.
        $cluster->kind === ClusterKind::Created ? ProvisionCluster::dispatch($cluster) : InstallClusterParts::dispatch($cluster);
    }
}
