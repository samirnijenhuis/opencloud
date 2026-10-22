import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { DnsBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { timeAgo } from '@/lib/format';
import { type DnsStatus } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Domain = { hostname: string; dns_status: DnsStatus; dns_found: string[]; dns_checked_at: string | null };
type Props = { address: string; ingressIp: string | null; domain: Domain | null };

function Guidance({ domain, ingressIp }: { domain: Domain; ingressIp: string | null }) {
    if (domain.dns_status === 'ok') {
        return <p className="text-sm">The A record points at {ingressIp}. The dashboard uses this domain with HTTPS.</p>;
    }

    if (domain.dns_status === 'proxied') {
        return <p className="text-sm">The domain goes through Cloudflare. The dashboard uses this domain with HTTPS.</p>;
    }

    return (
        <div className="space-y-1 text-sm">
            <p>
                Add an A record for <strong className="font-mono">{domain.hostname}</strong> that points to{' '}
                <strong className="font-mono">{ingressIp ?? 'the address of this server'}</strong>.
            </p>
            {domain.dns_status === 'wrong' && (
                <p className="text-red-700 dark:text-red-400">
                    We found {domain.dns_found.length > 0 ? domain.dns_found.join(', ') : 'another address'}. That address is not this server.
                </p>
            )}
            {domain.dns_status === 'missing' && (
                <p className="text-amber-700 dark:text-amber-400">We found no A record yet. DNS changes can take a few minutes.</p>
            )}
            <p className="text-muted-foreground">
                opencloud checks again by itself until the record is right. The address on port 8000 keeps working.
            </p>
        </div>
    );
}

export default function DashboardAddress({ address, ingressIp, domain }: Props) {
    const form = useForm({ hostname: domain?.hostname ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('dashboard-domain.update'), { preserveScroll: true });
    };

    const remove = () => {
        if (confirm('Remove the domain? The dashboard stays at the address on port 8000.')) {
            router.delete(route('dashboard-domain.destroy'), { preserveScroll: true, onSuccess: () => form.setData('hostname', '') });
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Dashboard address', href: route('dashboard-domain.edit') }]}>
            <Head title="Dashboard address" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Dashboard address" description="Give the dashboard its own domain with HTTPS." />

                    <p className="text-sm">
                        Current address: <strong className="font-mono">{address}</strong>
                    </p>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="hostname">Domain</Label>
                            <Input
                                id="hostname"
                                value={form.data.hostname}
                                onChange={(e) => form.setData('hostname', e.target.value)}
                                placeholder="opencloud.example.com"
                            />
                            <InputError message={form.errors.hostname} />
                        </div>
                        <Button disabled={form.processing}>{domain ? 'Change domain' : 'Use this domain'}</Button>
                    </form>

                    {domain && (
                        <div className="space-y-2 rounded-md border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-sm">{domain.hostname}</span>
                                <DnsBadge status={domain.dns_status} />
                                <span className="ml-auto flex gap-1">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => router.post(route('dashboard-domain.check'), {}, { preserveScroll: true })}
                                    >
                                        Check again
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={remove}>
                                        Remove
                                    </Button>
                                </span>
                            </div>
                            <Guidance domain={domain} ingressIp={ingressIp} />
                            {domain.dns_checked_at && <p className="text-muted-foreground text-xs">Last check {timeAgo(domain.dns_checked_at)}</p>}
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
