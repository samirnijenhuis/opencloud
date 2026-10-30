<?php

namespace App\Domains\Environments\Jobs;

use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Environments\Actions\SleepEnvironment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\CaddyMetrics;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Runs each minute. On each cluster with an awake environment that can sleep, it reads the request counters of Caddy,
 * stores the time of the last request of each environment, and puts each idle environment to sleep. An environment is
 * idle when its last request, its last wake, and the time its live deployment went live are all older than its sleep
 * minutes.
 */
class SleepIdleEnvironments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The lock must expire on its own, because a job that fails before it runs never releases it. */
    public int $uniqueFor = 60;

    public function handle(CaddyMetrics $metrics, Repository $cache, SleepEnvironment $sleep): void
    {
        Environment::query()
            ->whereNotNull('sleep_after_minutes')
            ->whereNull('slept_at')
            ->whereHas('deployments', fn (Builder $deployment) => $deployment->where('status', DeploymentStatus::LIVE))
            ->with(['application.cluster', 'domains', 'deployments' => fn (HasMany $deployment) => $deployment->where('status', DeploymentStatus::LIVE)])
            ->get()
            ->groupBy('application.cluster_id')
            ->each(function (Collection $environments) use ($metrics, $cache, $sleep) {
                $cluster = $environments->first()->application->cluster;
                $counts = $metrics->requestCounts($cluster);

                // Without the counters, an environment with visitors looks idle.
                if ($counts === null) {
                    return;
                }

                // Caddy counts from its start, so a counter that moved either way means requests since the last read.
                $key = "caddy-request-counts-{$cluster->id}";
                $requested = array_keys(array_diff_assoc($counts, $cache->get($key, [])));

                [$active, $quiet] = $environments->partition(fn (Environment $environment) => $environment->domains->whereIn('hostname', $requested)->isNotEmpty());
                Environment::query()->whereKey($active->pluck('id')->all())->update(['last_request_at' => now()]);
                // After the write, so a run that stops before it finds the same requests again.
                $cache->forever($key, $counts);

                $quiet
                    ->filter(fn (Environment $environment) => self::awakeSince($environment)->isBefore(now()->subMinutes($environment->sleep_after_minutes)))
                    ->each(fn (Environment $environment) => $sleep->execute($environment));
            });
    }

    private static function awakeSince(Environment $environment): Carbon
    {
        return collect([$environment->last_request_at, $environment->woke_at, $environment->deployments->first()->finished_at])->filter()->max();
    }
}
