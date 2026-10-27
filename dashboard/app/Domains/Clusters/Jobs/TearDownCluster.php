<?php

namespace App\Domains\Clusters\Jobs;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\ClusterProviders;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Support\Tofu\Runner;
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

    public function handle(Runner $runner, ClusterProviders $providers): void
    {
        $cluster = $this->cluster;
        $cluster->update(['status' => ClusterStatus::DESTROYING, 'error' => null, 'tofu_log' => null]);

        try {
            $runner->destroy($providers->for($cluster->provider)->stack($cluster), $cluster);
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
        $this->cluster->update(['status' => ClusterStatus::FAILED, 'error' => 'The destroy failed. '.($e?->getMessage() ?? '')]);
    }
}
