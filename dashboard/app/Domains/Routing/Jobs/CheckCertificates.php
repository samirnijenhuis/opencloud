<?php

namespace App\Domains\Routing\Jobs;

use App\Domains\Routing\CertificateProbe;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records whether Caddy serves a valid certificate for each domain that points at its cluster.
 * It also checks issued certificates, so an expired one shows as pending again.
 */
class CheckCertificates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The lock must expire on its own, because a job that fails before it runs never releases it. */
    public int $uniqueFor = 60;

    public function handle(CertificateProbe $probe): void
    {
        Domain::query()
            ->whereIn('dns_status', [DnsStatus::OK, DnsStatus::PROXIED])
            ->with('environment.application.cluster')
            ->each(function (Domain $domain) use ($probe) {
                $address = $domain->environment->application->cluster->ingress_ip;

                if ($address === null) {
                    return;
                }

                $certificate = $probe->certificate($address, $domain->hostname);
                $issued = CertificateProbe::covers($certificate, $domain->hostname, now()->getTimestamp());
                $domain->update(['certificate_status' => $issued ? 'issued' : 'pending']);
            });
    }
}
