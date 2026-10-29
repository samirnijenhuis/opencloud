<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use App\Domains\Environments\Models\Environment;
use Illuminate\Database\Eloquent\Builder;

class SleepEnvironment
{
    /**
     * Marks an idle environment as sleeping and queues the scale to zero. The databases, the buckets, and the volumes stay.
     * Only opencloud puts an environment to sleep, so the audit event has no user.
     */
    public function execute(Environment $environment): void
    {
        // A deployment that starts after the idle check must keep the environment awake, so one statement checks and writes.
        $slept = Environment::query()
            ->whereKey($environment->id)
            ->whereNull('slept_at')
            ->whereDoesntHave('deployments', fn (Builder $deployment) => $deployment->whereIn('status', array_filter(DeploymentStatus::cases(), fn (DeploymentStatus $status) => $status->isRunning())))
            ->update(['slept_at' => now()]) === 1;

        if (! $slept) {
            return;
        }

        AuditEvent::record(null, 'environment.slept', $environment, ['sleep_after_minutes' => $environment->sleep_after_minutes]);
        ScaleEnvironment::dispatch($environment)->afterCommit();
    }
}
