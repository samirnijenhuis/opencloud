<?php

namespace App\Actions;

use App\Jobs\SyncCaddy;
use App\Models\AuditEvent;
use App\Models\DashboardDomain;
use App\Models\User;

class RemoveDashboardDomain
{
    public function handle(DashboardDomain $domain, User $by): void
    {
        $domain->delete();

        AuditEvent::record($by, 'dashboard_domain.removed', null, ['hostname' => $domain->hostname]);

        if (($cluster = DashboardDomain::localCluster()) !== null) {
            SyncCaddy::dispatch($cluster)->afterCommit();
        }
    }
}
