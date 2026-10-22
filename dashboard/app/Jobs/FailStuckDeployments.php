<?php

namespace App\Jobs;

use App\Deploy\DeploymentLog;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fails deployments that a dead worker left in building or deploying. RunDeployment times out after 60 minutes
 * and moves the status at each step, so no live worker leaves a deployment unchanged for 70 minutes.
 */
class FailStuckDeployments implements ShouldQueue
{
    use Queueable;

    public const STALE_MINUTES = 70;

    public function handle(): void
    {
        Deployment::query()
            ->whereIn('status', [DeploymentStatus::Building, DeploymentStatus::Deploying])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->each(function (Deployment $deployment) {
                DeploymentLog::for($deployment)->write('The worker stopped during this deployment.');
                $deployment->finished_at = now();
                $deployment->moveTo(DeploymentStatus::Failed);
            });
    }
}
