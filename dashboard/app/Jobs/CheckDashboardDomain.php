<?php

namespace App\Jobs;

use App\Domains\DnsResolver;
use App\Domains\DnsStatus;
use App\Models\DashboardDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

            return;
        }

        if ($this->attempts() < CheckDomain::MAX_TRIES) {
            $this->release(CheckDomain::delay($this->attempts()));
        }
    }
}
