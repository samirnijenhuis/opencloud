<?php

namespace App\Domains\Routing\Jobs;

use App\Domains\Routing\DnsResolver;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks the A records of a domain until they point at the cluster. A passed check puts the domain into Caddy.
 */
class CheckDomain implements ShouldQueue
{
    use Queueable;

    /** With the growing delay, 48 tries cover about two days. */
    public const MAX_TRIES = 48;

    public int $tries = self::MAX_TRIES;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Domain $domain) {}

    public function handle(DnsResolver $resolver): void
    {
        $domain = $this->domain;
        $cluster = $domain->environment->application->cluster;
        $found = $resolver->ipv4($domain->hostname);
        $status = DnsStatus::forAddresses($found, $cluster->ingress_ip);

        $domain->update(['dns_status' => $status, 'dns_found' => $found, 'dns_checked_at' => now()]);

        if ($status->routable()) {
            SyncCaddy::dispatch($cluster);

            return;
        }

        if ($this->attempts() < self::MAX_TRIES) {
            $this->release(self::delay($this->attempts()));
        }
    }

    /** One minute after the first try, doubling up to one hour. */
    public static function delay(int $attempt): int
    {
        return min(60 * 2 ** ($attempt - 1), 3600);
    }
}
