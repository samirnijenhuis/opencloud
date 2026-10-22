<?php

namespace App\Actions;

use App\Enums\ClusterKind;
use App\Kube\Kubectl;
use App\Kube\KubectlException;
use App\Kube\Manifests\ClusterPartsManifests;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DisconnectCluster
{
    /**
     * Forgets the cluster. With $removeParts, it first deletes the opencloud namespaces on the cluster and nothing else.
     *
     * @throws ValidationException for the local cluster, while applications or datastores use the cluster, or when the delete fails
     */
    public function handle(Cluster $cluster, User $by, bool $removeParts): void
    {
        if ($cluster->kind === ClusterKind::Local) {
            throw ValidationException::withMessages(['cluster' => 'The local cluster runs the dashboard, so it cannot be disconnected.']);
        }

        $applications = $cluster->applications()->count();
        $datastores = $cluster->datastores()->count();

        if ($applications > 0 || $datastores > 0) {
            throw ValidationException::withMessages(['cluster' => "This cluster still runs {$applications} applications and {$datastores} datastores. Remove them first."]);
        }

        if ($removeParts) {
            $kubectl = Kubectl::for($cluster);

            try {
                foreach (ClusterPartsManifests::NAMESPACES as $namespace) {
                    $kubectl->delete('namespace', $namespace);
                }
            } catch (KubectlException $e) {
                throw ValidationException::withMessages(['cluster' => "Opencloud could not remove its namespaces. {$e->getMessage()} Try again, or disconnect without removing them."]);
            }
        }

        AuditEvent::record($by, 'cluster.disconnected', $cluster, ['name' => $cluster->name, 'removed_parts' => $removeParts]);
        $cluster->delete();
    }
}
