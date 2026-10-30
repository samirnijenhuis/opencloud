<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Jobs\ScaleEnvironment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;

class WakeEnvironment
{
    /**
     * Marks a sleeping environment as awake and queues the scale back to its instances. A null user is a visitor of the site.
     *
     * @return Environment the environment as it is now
     */
    public function execute(Environment $environment, ?User $by): Environment
    {
        // Each reload of the Starting page calls this, and the model of a caller can be older than a sleep that came after
        // it. One statement reads and clears the state, so only one call wakes the environment.
        $woke = Environment::query()
            ->whereKey($environment->id)
            ->whereNotNull('slept_at')
            ->update(['slept_at' => null, 'woke_at' => now()]) === 1;

        if ($woke) {
            AuditEvent::record($by, 'environment.woke', $environment);
        }

        $environment = $environment->fresh();

        // A later request that still finds no pod sends the scale again, so a scale job that failed converges on a reload.
        if ($environment->isWaking()) {
            ScaleEnvironment::dispatch($environment)->afterCommit();
        }

        return $environment;
    }
}
