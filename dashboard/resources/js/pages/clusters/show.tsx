import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { busyStatuses, ClusterBadge, kindLabels, type ClusterKind, type ClusterStatus } from '@/pages/clusters/cluster-status';
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

type Created = {
    region: string;
    node_size: string;
    min_nodes: number;
    max_nodes: number;
    tofu_log: string | null;
    can_destroy: boolean;
};

type Props = {
    cluster: Cluster;
    applications: { id: number; name: string; repository: string }[];
    datastores: { id: number; kind: string }[];
    can: { reinstall: boolean; disconnect: boolean };
    created: Created | null;
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

export default function ClustersShow({ cluster, applications, datastores, can, created }: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [removeParts, setRemoveParts] = useState(true);
    const [destroying, setDestroying] = useState(false);
    const [confirmName, setConfirmName] = useState('');
    const busy = busyStatuses.includes(cluster.status);

    useEffect(() => {
        if (!busy) {
            return;
        }

        const timer = window.setInterval(() => router.reload({ only: ['cluster', 'created'] }), REFRESH_MS);

        return () => window.clearInterval(timer);
    }, [busy]);

    const reinstall = () => router.post(route('clusters.reinstall', cluster.id), {}, { preserveScroll: true });

    const disconnect = () => {
        const what = removeParts
            ? 'Opencloud deletes its namespaces opencloud and oc-datastores on the cluster.'
            : 'The cluster keeps the opencloud parts.';

        if (confirm(`Disconnect ${cluster.name}? ${what}`)) {
            router.delete(route('clusters.destroy', cluster.id), { data: { remove_parts: removeParts }, preserveScroll: true });
        }
    };

    const destroy = () =>
        router.delete(route('clusters.digitalocean.destroy', cluster.id), {
            data: { confirm: confirmName },
            preserveScroll: true,
            onSuccess: () => {
                setDestroying(false);
                setConfirmName('');
            },
        });

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

                {cluster.status === 'provisioning' && (
                    <p className="text-sm">OpenTofu creates the cluster on DigitalOcean. This takes about ten minutes.</p>
                )}
                {cluster.status === 'installing' && (
                    <p className="text-sm">Opencloud installs its parts and waits for the load balancer. This can take a few minutes.</p>
                )}
                {cluster.status === 'destroying' && <p className="text-sm">OpenTofu destroys the cluster on DigitalOcean.</p>}

                {cluster.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                        <p className="font-medium">The last run failed.</p>
                        <p className="mt-1 font-mono text-xs break-words whitespace-pre-wrap">{cluster.error}</p>
                    </div>
                )}

                <dl className="divide-y rounded-lg border px-4">
                    <Row label="Kind">{kindLabels[cluster.kind]}</Row>
                    {created && (
                        <Row label="DigitalOcean">
                            <span className="font-mono">
                                {created.region}, {created.node_size}
                            </span>
                            , {created.min_nodes} to {created.max_nodes} nodes
                        </Row>
                    )}
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

                {created?.tofu_log && (
                    <details className="rounded-lg border p-4">
                        <summary className="cursor-pointer text-sm font-medium">OpenTofu log</summary>
                        <pre className="bg-muted mt-3 max-h-96 overflow-auto rounded p-3 font-mono text-xs whitespace-pre-wrap">
                            {created.tofu_log}
                        </pre>
                    </details>
                )}

                {(can.reinstall || can.disconnect) && (
                    <section className="space-y-4 rounded-lg border p-4">
                        {can.reinstall && (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm">
                                    {created
                                        ? 'Apply the OpenTofu module and the parts of this opencloud version again. Apps keep running.'
                                        : 'Apply the parts of this opencloud version again. Apps keep running.'}
                                </p>
                                <Button variant="secondary" onClick={reinstall} disabled={busy}>
                                    Reinstall parts
                                </Button>
                            </div>
                        )}
                        {created?.can_destroy && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                                <p className="text-sm">Delete the cluster, its nodes, and its volumes on DigitalOcean.</p>
                                <Button variant="destructive" onClick={() => setDestroying(true)} disabled={busy}>
                                    Destroy cluster
                                </Button>
                            </div>
                        )}
                        {can.disconnect && !created && (
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

            <Dialog open={destroying} onOpenChange={setDestroying}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Destroy {cluster.name} on DigitalOcean?</DialogTitle>
                        <DialogDescription>
                            OpenTofu deletes the cluster, its nodes, and its volumes. You cannot undo this. The container registry stays, because
                            other clusters can use it. Delete it on DigitalOcean when you no longer need it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="confirm">Type {cluster.name} to confirm</Label>
                        <Input id="confirm" autoComplete="off" value={confirmName} onChange={(e) => setConfirmName(e.target.value)} />
                        <InputError message={errors.confirm ?? errors.cluster} />
                    </div>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDestroying(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={confirmName !== cluster.name} onClick={destroy}>
                            Destroy cluster
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
