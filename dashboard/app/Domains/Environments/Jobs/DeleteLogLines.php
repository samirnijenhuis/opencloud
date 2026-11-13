<?php

namespace App\Domains\Environments\Jobs;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\LogStore;
use App\Domains\Environments\Models\Environment;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the Loki of the cluster to delete the lines of one environment namespace in a range. The job takes the namespace
 * and not the environment, because DeleteEnvironment and ClosePreview delete the environment before the job runs.
 * A second run sends the same request again, and Loki deletes the same lines.
 */
class DeleteLogLines implements ShouldQueue
{
    use Queueable;

    /** Loki refuses a delete that ends in its future, so a cluster clock behind the dashboard clock fails the first try. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    /** DisconnectCluster and TearDownCluster delete the cluster, and the dashboard then cannot reach its Loki. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Cluster $cluster, public string $namespace, public CarbonInterface $from, public CarbonInterface $to) {}

    /**
     * Queues the delete of each line of an environment whose namespace goes now. Its pods can write lines while they stop,
     * within the default grace period of 30 seconds, so the range ends 1 minute from now, and the job waits until then.
     */
    public static function dispatchForDeleted(Environment $environment): PendingDispatch
    {
        $end = now()->addMinute();

        return self::dispatch($environment->application->cluster, $environment->kubeNamespace(), $environment->created_at, $end)->delay($end)->afterCommit();
    }

    public function handle(LogStore $logStore): void
    {
        $logStore->delete($this->cluster, '{namespace='.LogStore::quote($this->namespace).'}', $this->from, $this->to);
    }
}
