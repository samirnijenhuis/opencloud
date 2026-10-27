<?php

namespace App\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDashboardDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\DashboardDomain;
use Illuminate\Support\Facades\DB;

class SetDashboardDomain
{
    /** A new row replaces the old one, so the retries of the old check find no model and stop. */
    public function execute(User $by, string $hostname): DashboardDomain
    {
        $hostname = rtrim(strtolower(trim($hostname)), '.');
        $hadRoute = DashboardDomain::verified() !== null;

        $domain = DB::transaction(function () use ($hostname) {
            DashboardDomain::query()->delete();

            return DashboardDomain::create(['hostname' => $hostname]);
        });

        AuditEvent::record($by, 'dashboard_domain.set', $domain, ['hostname' => $hostname]);
        CheckDashboardDomain::dispatch($domain)->afterCommit();

        // The route of the replaced domain must leave Caddy.
        if ($hadRoute && ($cluster = DashboardDomain::localCluster()) !== null) {
            SyncCaddy::dispatch($cluster)->afterCommit();
        }

        return $domain;
    }
}
