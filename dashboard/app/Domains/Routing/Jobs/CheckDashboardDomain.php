<?php

namespace App\Domains\Routing\Jobs;

use App\Domains\Routing\DnsResolver;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\DashboardDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Checks the A records of the dashboard domain until they point at the local cluster. A passed check puts the domain into Caddy.
 * Removing the domain deletes the row, so a retry for a removed domain stops.
 */
class CheckDashboardDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = CheckDomain::MAX_TRIES;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public DashboardDomain $domain) {}

    public function handle(DnsResolver $resolver): void
    {
        $cluster = DashboardDomain::localCluster();
        $found = $resolver->ipv4($this->domain->hostname);
        $status = DnsStatus::forAddresses($found, $cluster?->ingress_ip);

        $this->domain->update(['dns_status' => $status, 'dns_found' => $found, 'dns_checked_at' => now()]);

        if ($status->routable()) {
            if ($cluster !== null) {
                SyncCaddy::dispatch($cluster);
            }

            // Workers read the root URL when they boot, so they restart to pick up the new address.
            Artisan::call('queue:restart');

            return;
        }

        if ($this->attempts() < CheckDomain::MAX_TRIES) {
            $this->release(CheckDomain::delay($this->attempts()));
        }
    }
}
