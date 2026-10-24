import InputError from '@/components/input-error';
import { DnsBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeAgo } from '@/lib/format';
import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef } from 'react';
import { EditorSheet } from './editor-sheet';
import { type Domain } from './types';

const inZone = (hostname: string, zones: string[]) => zones.some((zone) => hostname === zone || hostname.endsWith(`.${zone}`));

export const certificateLabels: Record<string, string> = {
    pending: 'Certificate pending',
    issued: 'Certificate issued',
    failed: 'Certificate failed',
};

function Guidance({ domain, ingressIp }: { domain: Domain; ingressIp: string | null }) {
    const address = ingressIp ?? 'the cluster address';

    if (domain.generated) {
        return <p className="text-muted-foreground text-sm">sslip.io sends this name to {address}, so it needs no DNS record.</p>;
    }

    if (domain.dns_mode === 'cloudflare' && domain.dns_status !== 'ok' && domain.dns_status !== 'proxied') {
        return (
            <p className="text-sm">
                opencloud manages the DNS record in Cloudflare. It waits for the record to show up
                {domain.dns_status === 'wrong' && domain.dns_found.length > 0 ? `. We found ${domain.dns_found.join(', ')}.` : '.'}
            </p>
        );
    }

    if (domain.dns_status === 'ok') {
        return <p className="text-muted-foreground text-sm">The A record points at {address}.</p>;
    }

    if (domain.dns_status === 'proxied') {
        return <p className="text-muted-foreground text-sm">The domain goes through Cloudflare. Cloudflare sends traffic to the cluster.</p>;
    }

    return (
        <div className="space-y-1 text-sm">
            <p>
                Add an A record for <strong className="font-mono break-all">{domain.hostname}</strong> that points to{' '}
                <strong className="font-mono">{address}</strong>.
            </p>
            {!ingressIp && <p className="text-muted-foreground">The cluster has no ingress address yet. Open the clusters page to see its state.</p>}
            {domain.dns_status === 'wrong' && (
                <p className="text-red-700 dark:text-red-400">
                    We found {domain.dns_found.length > 0 ? domain.dns_found.join(', ') : 'another address'}. That address is not the cluster.
                </p>
            )}
            {domain.dns_status === 'missing' && (
                <p className="text-amber-700 dark:text-amber-400">We found no A record yet. DNS changes can take a few minutes.</p>
            )}
            <p className="text-muted-foreground">opencloud checks again by itself until the record is right.</p>
        </div>
    );
}

export function DomainsSheet({
    open,
    onClose,
    focusAdd,
    environmentId,
    domains,
    ingressIp,
    cloudflareZones,
    canManage,
}: {
    open: boolean;
    onClose: () => void;
    focusAdd: boolean;
    environmentId: number;
    domains: Domain[];
    ingressIp: string | null;
    cloudflareZones: string[];
    canManage: boolean;
}) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const form = useForm<{ hostname: string; manage_dns: boolean; proxied: boolean }>({ hostname: '', manage_dns: false, proxied: false });
    const offerCloudflare = inZone(form.data.hostname.trim().toLowerCase(), cloudflareZones);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open && focusAdd) {
            // The sheet moves focus to itself when it opens, so the input takes it one frame later.
            requestAnimationFrame(() => input.current?.focus());
        }
    }, [open, focusAdd]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('domains.store', environmentId), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const check = (domain: Domain) => router.post(route('domains.check', domain.id), {}, { preserveScroll: true });

    const remove = (domain: Domain) => {
        if (confirm(`Remove ${domain.hostname}? The site stops answering on this name.`)) {
            router.delete(route('domains.destroy', domain.id), { preserveScroll: true });
        }
    };

    return (
        <EditorSheet
            open={open}
            onClose={onClose}
            title="Domains"
            description="Caddy serves each domain and gets its certificate as soon as the DNS record points to the cluster. No deploy is necessary."
        >
            {errors.domain && <p className="text-sm text-red-700 dark:text-red-400">{errors.domain}</p>}

            {domains.length === 0 ? (
                <p className="text-sm">This environment has no domain yet.</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {domains.map((domain) => (
                        <li key={domain.id} className="space-y-2 p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="min-w-0 font-mono text-sm font-medium break-all">{domain.hostname}</span>
                                {domain.generated && <Badge variant="outline">Free address</Badge>}
                            </div>
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <DnsBadge status={domain.dns_status} />
                                <span className="text-muted-foreground">
                                    {certificateLabels[domain.certificate_status] ?? domain.certificate_status}
                                </span>
                                {domain.dns_mode === 'cloudflare' && <span className="text-muted-foreground">DNS in Cloudflare</span>}
                            </div>
                            <Guidance domain={domain} ingressIp={ingressIp} />
                            <div className="flex flex-wrap items-center gap-2">
                                {domain.dns_checked_at && (
                                    <span className="text-muted-foreground text-xs">Last check {timeAgo(domain.dns_checked_at)}</span>
                                )}
                                {canManage && (
                                    <span className="ml-auto flex gap-1">
                                        <Button variant="secondary" size="sm" onClick={() => check(domain)}>
                                            Check again
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={() => remove(domain)}>
                                            Remove
                                        </Button>
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canManage ? (
                <form onSubmit={submit} className="space-y-3">
                    <Label htmlFor="hostname">Add a domain</Label>
                    <div className="flex gap-2">
                        <Input
                            ref={input}
                            id="hostname"
                            placeholder="shop.example.com"
                            value={form.data.hostname}
                            onChange={(e) => form.setData('hostname', e.target.value)}
                        />
                        <Button disabled={form.processing || form.data.hostname.trim() === ''}>Add domain</Button>
                    </div>
                    <InputError message={form.errors.hostname} />
                    {offerCloudflare && (
                        <div className="space-y-2 text-sm">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="manage_dns"
                                    checked={form.data.manage_dns}
                                    onCheckedChange={(checked) => form.setData('manage_dns', checked === true)}
                                />
                                <Label htmlFor="manage_dns">Let opencloud manage the DNS record</Label>
                            </div>
                            {form.data.manage_dns && (
                                <div className="flex items-center gap-2 pl-6">
                                    <Checkbox
                                        id="proxied"
                                        checked={form.data.proxied}
                                        onCheckedChange={(checked) => form.setData('proxied', checked === true)}
                                    />
                                    <Label htmlFor="proxied">Send traffic through the Cloudflare proxy</Label>
                                </div>
                            )}
                        </div>
                    )}
                    <InputError message={form.errors.manage_dns} />
                </form>
            ) : (
                <p className="text-muted-foreground text-sm">Only an admin or an owner can add or remove domains.</p>
            )}
        </EditorSheet>
    );
}
