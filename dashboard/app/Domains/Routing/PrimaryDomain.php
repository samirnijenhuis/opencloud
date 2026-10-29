<?php

namespace App\Domains\Routing;

use App\Domains\Routing\Models\Domain;
use Illuminate\Support\Collection;

/**
 * The domain that sets APP_URL, the Host header of the readiness probe, and the Visit site link. The domain that a user
 * made primary wins. Else it is the oldest custom domain that is live, else the free domain, else the oldest domain.
 */
final class PrimaryDomain
{
    /** @param  Collection<int, Domain>  $domains  the domains of one environment */
    public static function of(Collection $domains): ?Domain
    {
        $domains = $domains->sortBy('id');

        return $domains->firstWhere('is_primary', true)
            ?? $domains->first(fn (Domain $domain) => ! $domain->generated && $domain->dns_status->routable() && $domain->certificate_status === 'issued')
            ?? $domains->firstWhere('generated', true)
            ?? $domains->first();
    }
}
