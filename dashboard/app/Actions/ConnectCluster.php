<?php

namespace App\Actions;

use App\Clusters\ClusterStatus;
use App\Enums\ClusterKind;
use App\Jobs\InstallClusterParts;
use App\Kube\Kubectl;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConnectCluster
{
    /**
     * Checks the kubeconfig, stores the cluster with its registry, and starts the install of the opencloud parts.
     *
     * @param  array{name: string, kubeconfig: string, registry_url: string, registry_username: string, registry_password: string, registry_insecure: bool}  $data
     *
     * @throws ValidationException when kubectl cannot reach the cluster or lacks full rights on it
     */
    public function handle(User $by, array $data): Cluster
    {
        $cluster = new Cluster([...$data, 'kind' => ClusterKind::Connected, 'status' => ClusterStatus::Installing]);
        self::check($cluster);
        $cluster->save();

        AuditEvent::record($by, 'cluster.connected', $cluster, ['name' => $cluster->name, 'registry' => $cluster->registry_url]);
        InstallClusterParts::dispatch($cluster)->afterCommit();

        return $cluster;
    }

    private static function check(Cluster $cluster): void
    {
        $kubectl = Kubectl::for($cluster);
        $version = $kubectl->run(['version', '--request-timeout=15s'], timeout: 30);

        if (! $version->ok()) {
            $said = trim(strtok($version->error ?: $version->output, "\n") ?: 'nothing');

            throw ValidationException::withMessages(['kubeconfig' => "Opencloud cannot reach the cluster with this kubeconfig. kubectl said: {$said}"]);
        }

        $rights = $kubectl->run(['auth', 'can-i', '*', '*', '--all-namespaces', '--request-timeout=15s'], timeout: 30);

        if (trim($rights->output) !== 'yes') {
            throw ValidationException::withMessages(['kubeconfig' => 'This kubeconfig does not have full rights on the cluster. Opencloud needs a kubeconfig with the cluster-admin role.']);
        }
    }
}
