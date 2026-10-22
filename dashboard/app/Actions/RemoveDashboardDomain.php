<?php

namespace App\Actions;

use App\Jobs\SyncCaddy;
use App\Models\AuditEvent;
use App\Models\DashboardDomain;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

class RemoveDashboardDomain
{
    public function handle(DashboardDomain $domain, User $by): void
    {
        $domain->delete();

        AuditEvent::record($by, 'dashboard_domain.removed', null, ['hostname' => $domain->hostname]);

        if (($cluster = DashboardDomain::localCluster()) !== null) {
            SyncCaddy::dispatch($cluster)->afterCommit();
        }

        // Workers read the root URL when they boot, so they restart to drop the old address.
        Artisan::call('queue:restart');
    }
}
