<?php

namespace App\Jobs;

use App\Clusters\ClusterStatus;
use App\Clusters\DigitalOceanCluster;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Tofu\Runner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs tofu destroy for a created cluster, then removes the record. The standard datastores lived in the cluster, so
 * their records go too. A failed destroy keeps the record and the state, so "Destroy" can run again.
 */
class TearDownCluster implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3000;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Cluster $cluster)
    {
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
        $cluster->update(['status' => ClusterStatus::Destroying, 'error' => null, 'tofu_log' => null]);

        try {
            $runner->destroy(DigitalOceanCluster::stack($cluster, DigitalOceanCluster::token()), $cluster);
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        DB::transaction(function () use ($cluster) {
            $cluster->datastores()->delete();
            AuditEvent::record(null, 'cluster.destroyed', $cluster, ['name' => $cluster->name]);
            $cluster->delete();
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->cluster->update(['status' => ClusterStatus::Failed, 'error' => 'The destroy failed. '.($e?->getMessage() ?? '')]);
    }
}
