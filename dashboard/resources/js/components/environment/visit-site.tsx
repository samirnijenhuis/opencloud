import { type Tone } from '@/components/environment/block';
import { type Domain } from '@/components/environment/types';
import { Button } from '@/components/ui/button';
import { ExternalLink } from 'lucide-react';

const live = (domain: Pick<Domain, 'dns_status'>) => domain.dns_status === 'ok' || domain.dns_status === 'proxied';

/** The one short state of a domain that fits in a block row. */
export function domainState(domain: Pick<Domain, 'dns_status' | 'certificate_status'>): [Tone, string] {
    if (domain.dns_status === 'wrong') return ['bad', 'Wrong address'];
    if (domain.dns_status === 'missing') return ['warn', 'Add A record'];
    if (!live(domain)) return ['off', 'Checking'];
    if (domain.certificate_status === 'failed') return ['bad', 'Certificate failed'];
    if (domain.certificate_status !== 'issued') return ['warn', 'Issuing'];

    return ['ok', 'Live'];
}

/** The header link opens the primary domain, so it opens the same address as APP_URL. */
export function VisitSite({ primary }: { primary: Pick<Domain, 'hostname' | 'dns_status' | 'certificate_status'> | null | undefined }) {
    if (!primary || domainState(primary)[0] !== 'ok') {
        return (
            <span
                title={
                    primary
                        ? 'The primary domain is not live yet. Open Domains to see why.'
                        : 'No domain is live yet. Add a domain, or wait for its certificate.'
                }
            >
                <Button variant="outline" disabled>
                    <ExternalLink /> Visit site
                </Button>
            </span>
        );
    }

    return (
        <Button variant="outline" asChild>
            <a href={`https://${primary.hostname}`} target="_blank" rel="noreferrer" title={primary.hostname}>
                <ExternalLink /> Visit site
            </a>
        </Button>
    );
}
