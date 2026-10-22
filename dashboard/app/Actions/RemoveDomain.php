<?php

namespace App\Actions;

use App\Jobs\SyncCaddy;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\User;

class RemoveDomain
{
    public function handle(Domain $domain, User $by): void
    {
        $environment = $domain->environment;
        $domain->delete();

        AuditEvent::record($by, 'domain.removed', $environment, ['hostname' => $domain->hostname]);
        SyncCaddy::dispatch($environment->application->cluster)->afterCommit();
    }
}
