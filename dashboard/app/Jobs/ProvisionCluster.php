<?php

namespace App\Jobs;

use App\Clusters\ClusterProviders;
use App\Clusters\ClusterStatus;
use App\Models\Cluster;
use App\Tofu\Runner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Applies the stack of the provider of the cluster, stores the kubeconfig and the registry, and hands over to InstallClusterParts.
 * A second run converges, because tofu apply changes nothing that already matches.
 */
class ProvisionCluster implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3000;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Cluster $cluster)
    {
        // A new cluster takes minutes, so the job uses the worker with the long timeout.
        $this->onQueue('builds');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("tofu-cluster-{$this->cluster->id}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(Runner $runner, ClusterProviders $providers): void
    {
        $cluster = $this->cluster;
        $cluster->update(['status' => ClusterStatus::Provisioning, 'error' => null, 'tofu_log' => null]);

        $provider = $providers->for($cluster->provider);

        try {
            $outputs = $runner->apply($provider->stack($cluster), $cluster);
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        $cluster->update([...$provider->attributes($outputs), 'status' => ClusterStatus::Installing]);
        InstallClusterParts::dispatch($cluster);
    }

    public function failed(?Throwable $e): void
    {
        $this->cluster->update(['status' => ClusterStatus::Failed, 'error' => $e?->getMessage() ?? 'The OpenTofu run failed.']);
    }
}
