<?php

namespace App\Jobs;

use App\Clusters\ClusterStatus;
use App\Clusters\DigitalOceanCluster;
use App\Models\Cluster;
use App\Tofu\Runner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Applies tofu/digitalocean/cluster, stores the kubeconfig and the registry, and hands over to InstallClusterParts.
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

    public function handle(Runner $runner): void
    {
        $cluster = $this->cluster;
        $cluster->update(['status' => ClusterStatus::Provisioning, 'error' => null, 'tofu_log' => null]);

        try {
            $outputs = $runner->apply(DigitalOceanCluster::stack($cluster, DigitalOceanCluster::token()), $cluster);
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        $cluster->update([...DigitalOceanCluster::attributes($outputs), 'status' => ClusterStatus::Installing]);
        InstallClusterParts::dispatch($cluster);
    }

    public function failed(?Throwable $e): void
    {
        $this->cluster->update(['status' => ClusterStatus::Failed, 'error' => $e?->getMessage() ?? 'The OpenTofu run failed.']);
    }
}
