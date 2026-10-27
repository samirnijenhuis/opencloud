<?php

namespace App\Domains\Routing\Jobs;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Routing\CaddySync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the full Caddy config to one cluster. One worker serves the default queue, so two syncs never run at the same time.
 * A change during a running sync queues one more sync, so no change is lost.
 */
class SyncCaddy implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    /** The lock must expire on its own, because a job that fails before it runs never releases it. */
    public int $uniqueFor = 60;

    public function __construct(public Cluster $cluster) {}

    public function uniqueId(): string
    {
        return (string) $this->cluster->id;
    }

    public function handle(CaddySync $sync): void
    {
        $sync->sync($this->cluster);
    }
}
