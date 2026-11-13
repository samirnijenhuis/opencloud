<?php

namespace App\Domains\Environments\Jobs;

use App\Domains\Environments\LogStore;
use App\Domains\Environments\Models\Environment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the Loki of the cluster to delete each line of the environment from before the last clear of its logs.
 * A second run sends the same request again, and Loki deletes the same lines.
 */
class DeleteLogLines implements ShouldQueue
{
    use Queueable;

    /** Loki refuses a delete that ends in its future, so a cluster clock behind the dashboard clock fails the first try. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    /** ClosePreview and DeleteEnvironment delete the environment, together with its namespace. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Environment $environment) {}

    public function handle(LogStore $logStore): void
    {
        $environment = $this->environment;

        // No line of the namespace is older than the environment.
        $logStore->delete(
            $environment->application->cluster,
            '{namespace='.LogStore::quote($environment->kubeNamespace()).'}',
            $environment->created_at,
            $environment->logs_cleared_at,
        );
    }
}
