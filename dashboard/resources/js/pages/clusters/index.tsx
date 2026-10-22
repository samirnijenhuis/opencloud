import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

type Cluster = {
    id: number;
    name: string;
    kind: 'local' | 'connected' | 'created';
    ingress_ip: string | null;
    status: string;
    parts_version: string | null;
};

const kindLabels: Record<Cluster['kind'], string> = {
    local: 'Local, on the dashboard server',
    connected: 'Connected',
    created: 'Created by opencloud',
};

export default function ClustersIndex({ clusters }: { clusters: Cluster[] }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Clusters', href: '/clusters' }]}>
            <Head title="Clusters" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">Clusters</h1>

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
                                        <td className="p-3 font-medium">{cluster.name}</td>
                                        <td className="p-3">{kindLabels[cluster.kind]}</td>
                                        <td className="p-3 font-mono">{cluster.ingress_ip ?? '-'}</td>
                                        <td className="p-3">
                                            <Badge variant={cluster.status === 'ready' ? 'default' : 'secondary'}>{cluster.status}</Badge>
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
