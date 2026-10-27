<?php

namespace App\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\DashboardDomain;
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
