import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { ClusterBadge, kindLabels, type ClusterKind, type ClusterStatus } from '@/pages/clusters/cluster-status';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Cluster = {
    id: number;
    name: string;
    kind: ClusterKind;
    status: ClusterStatus;
    error: string | null;
    ingress_ip: string | null;
    parts_version: string | null;
    registry_url: string | null;
    registry_insecure: boolean;
};

type Props = {
    cluster: Cluster;
    applications: { id: number; name: string; repository: string }[];
    datastores: { id: number; kind: string }[];
    can: { reinstall: boolean; disconnect: boolean };
};

const REFRESH_MS = 3000;

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1 py-3 sm:grid-cols-3">
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="text-sm sm:col-span-2">{children}</dd>
        </div>
    );
}

export default function ClustersShow({ cluster, applications, datastores, can }: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [removeParts, setRemoveParts] = useState(true);
    const installing = cluster.status === 'installing';

    useEffect(() => {
        if (!installing) {
            return;
        }

        const timer = window.setInterval(() => router.reload({ only: ['cluster'] }), REFRESH_MS);

        return () => window.clearInterval(timer);
    }, [installing]);

    const reinstall = () => router.post(route('clusters.reinstall', cluster.id), {}, { preserveScroll: true });

    const disconnect = () => {
        const what = removeParts
            ? 'Opencloud deletes its namespaces opencloud and oc-datastores on the cluster.'
            : 'The cluster keeps the opencloud parts.';

        if (confirm(`Disconnect ${cluster.name}? ${what}`)) {
            router.delete(route('clusters.destroy', cluster.id), { data: { remove_parts: removeParts }, preserveScroll: true });
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clusters', href: '/clusters' },
                { title: cluster.name, href: `/clusters/${cluster.id}` },
            ]}
        >
            <Head title={cluster.name} />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">{cluster.name}</h1>
                    <ClusterBadge status={cluster.status} />
                </div>

                {installing && <p className="text-sm">Opencloud installs its parts and waits for the load balancer. This can take a few minutes.</p>}

                {cluster.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                        <p className="font-medium">The install failed.</p>
                        <p className="mt-1 font-mono text-xs break-words whitespace-pre-wrap">{cluster.error}</p>
                    </div>
                )}

                <dl className="divide-y rounded-lg border px-4">
                    <Row label="Kind">{kindLabels[cluster.kind]}</Row>
                    <Row label="Ingress address">
                        <span className="font-mono">{cluster.ingress_ip ?? '-'}</span>
                        {cluster.ingress_ip && <p className="text-muted-foreground mt-1">Point the A records of the domains on this cluster here.</p>}
                    </Row>
                    <Row label="Parts version">
                        <span className="font-mono">{cluster.parts_version ?? '-'}</span>
                    </Row>
                    {cluster.registry_url && (
                        <Row label="Registry">
                            <span className="font-mono">{cluster.registry_url}</span>
                            {cluster.registry_insecure && <span className="text-muted-foreground"> (plain HTTP)</span>}
                        </Row>
                    )}
                </dl>

                <section className="space-y-2">
                    <h2 className="text-lg font-medium">Applications</h2>
                    {applications.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No application runs on this cluster.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {applications.map((application) => (
                                <li key={application.id} className="flex items-center justify-between gap-3 p-3 text-sm">
                                    <Link href={route('applications.show', application.id)} className="font-medium hover:underline">
                                        {application.name}
                                    </Link>
                                    <span className="text-muted-foreground font-mono">{application.repository}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {datastores.length > 0 && (
                        <p className="text-muted-foreground text-sm">Datastores: {datastores.map((datastore) => datastore.kind).join(', ')}.</p>
                    )}
                </section>

                {(can.reinstall || can.disconnect) && (
                    <section className="space-y-4 rounded-lg border p-4">
                        {can.reinstall && (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm">Apply the parts of this opencloud version again. Apps keep running.</p>
                                <Button variant="secondary" onClick={reinstall} disabled={installing}>
                                    Reinstall parts
                                </Button>
                            </div>
                        )}
                        {can.disconnect && (
                            <div className="space-y-3 border-t pt-4">
                                <Label className="flex items-center gap-2 font-normal">
                                    <Checkbox checked={removeParts} onCheckedChange={(checked) => setRemoveParts(checked === true)} />
                                    Remove the opencloud namespaces from the cluster
                                </Label>
                                <Button variant="destructive" onClick={disconnect}>
                                    Disconnect
                                </Button>
                            </div>
                        )}
                        <InputError message={errors.cluster} />
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
