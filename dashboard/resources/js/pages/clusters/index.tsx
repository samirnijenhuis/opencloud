import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { ClusterBadge, kindLabels, type ClusterKind, type ClusterStatus } from '@/pages/clusters/cluster-status';
import { Head, Link } from '@inertiajs/react';

type Cluster = {
    id: number;
    name: string;
    kind: ClusterKind;
    ingress_ip: string | null;
    status: ClusterStatus;
    parts_version: string | null;
};

export default function ClustersIndex({ clusters, canConnect }: { clusters: Cluster[]; canConnect: boolean }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Clusters', href: '/clusters' }]}>
            <Head title="Clusters" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">Clusters</h1>
                    {canConnect && (
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="secondary">
                                <Link href={route('clusters.digitalocean.create')}>Create on DigitalOcean</Link>
                            </Button>
                            <Button asChild>
                                <Link href={route('clusters.create')}>Connect a cluster</Link>
                            </Button>
                        </div>
                    )}
                </div>

                {clusters.length === 0 ? (
                    <p className="text-muted-foreground text-sm">There are no clusters yet.</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-left">
                                <tr>
                                    <th className="p-3 font-medium">Name</th>
                                    <th className="p-3 font-medium">Kind</th>
                                    <th className="p-3 font-medium">Ingress IP</th>
                                    <th className="p-3 font-medium">Status</th>
                                    <th className="p-3 font-medium">Parts version</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {clusters.map((cluster) => (
                                    <tr key={cluster.id}>
                                        <td className="p-3 font-medium">
                                            <Link href={route('clusters.show', cluster.id)} className="hover:underline">
                                                {cluster.name}
                                            </Link>
                                        </td>
                                        <td className="p-3">{kindLabels[cluster.kind]}</td>
                                        <td className="p-3 font-mono">{cluster.ingress_ip ?? '-'}</td>
                                        <td className="p-3">
                                            <ClusterBadge status={cluster.status} />
                                        </td>
                                        <td className="p-3 font-mono">{cluster.parts_version ?? '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
