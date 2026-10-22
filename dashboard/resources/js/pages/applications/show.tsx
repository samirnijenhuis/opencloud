import { DeploymentBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type DeploymentStatus, type EnvironmentKind } from '@/types';
import { Head, Link } from '@inertiajs/react';

type EnvironmentRow = {
    id: number;
    name: string;
    kind: EnvironmentKind;
    is_protected: boolean;
    branch: string | null;
    pr_number: number | null;
    status: DeploymentStatus | null;
    url: string | null;
};

type Props = {
    application: { id: number; name: string; repository: string; type: 'laravel' | 'dockerfile' };
    environments: EnvironmentRow[];
};

export default function ApplicationShow({ application, environments }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Applications', href: route('applications.index') },
                { title: application.name, href: route('applications.show', application.id) },
            ]}
        >
            <Head title={application.name} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{application.name}</h1>
                    <p className="text-muted-foreground text-sm">
                        {application.repository} - {application.type === 'laravel' ? 'Laravel' : 'Dockerfile'}
                    </p>
                </div>

                <ul className="divide-y rounded-lg border">
                    {environments.map((environment) => (
                        <li key={environment.id} className="grid grid-cols-1 items-center gap-2 p-4 sm:grid-cols-[1fr_auto] sm:gap-6">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link href={route('environments.show', environment.id)} className="font-medium hover:underline">
                                        {environment.name}
                                    </Link>
                                    {environment.is_protected && <Badge variant="outline">Protected</Badge>}
                                    {environment.kind === 'preview' && <Badge variant="outline">Preview</Badge>}
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {environment.kind === 'preview' ? `Pull request ${environment.pr_number}` : `Branch ${environment.branch}`}
                                </p>
                                {environment.url && (
                                    <a
                                        href={environment.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-sm text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {environment.url.replace('https://', '')}
                                    </a>
                                )}
                            </div>
                            <DeploymentBadge status={environment.status} />
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
