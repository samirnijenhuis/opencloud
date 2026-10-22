<?php

namespace App\Jobs;

use App\Caddy\CaddySync;
use App\Models\Cluster;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends the full Caddy config to one cluster. A change during a running sync queues one more sync, so no change is lost.
 */
class SyncCaddy implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $backoff = 10;

    public function __construct(public Cluster $cluster) {}

    public function uniqueId(): string
    {
        return (string) $this->cluster->id;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("caddy-{$this->cluster->id}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(CaddySync $sync): void
    {
        $sync->sync($this->cluster);
    }
}
