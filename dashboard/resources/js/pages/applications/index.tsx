import { DeploymentBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import { type DeploymentStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Row = {
    id: number;
    name: string;
    repository: string;
    production_id: number | null;
    status: DeploymentStatus | null;
    url: string | null;
    deployed_at: string | null;
};

export default function ApplicationsIndex({ applications, canCreate }: { applications: Row[]; canCreate: boolean }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Applications', href: '/applications' }]}>
            <Head title="Applications" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">Applications</h1>
                    {canCreate && applications.length > 0 && (
                        <Button asChild>
                            <Link href={route('applications.create')}>New application</Link>
                        </Button>
                    )}
                </div>

                {applications.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed p-12 text-center">
                        <h2 className="text-lg font-medium">No applications yet</h2>
                        <p className="text-muted-foreground max-w-md text-sm">
                            {canCreate
                                ? 'Enter the name of a GitHub repository and opencloud builds and deploys it for you.'
                                : 'Ask an owner or an admin to create the first application.'}
                        </p>
                        {canCreate && (
                            <Button asChild>
                                <Link href={route('applications.create')}>New application</Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {applications.map((application) => (
                            <li key={application.id} className="grid grid-cols-1 items-center gap-2 p-4 sm:grid-cols-[1fr_auto_auto] sm:gap-6">
                                <div className="min-w-0">
                                    <Link href={route('applications.show', application.id)} className="font-medium hover:underline">
                                        {application.name}
                                    </Link>
                                    <p className="text-muted-foreground truncate text-sm">{application.repository}</p>
                                    {application.url && (
                                        <a
                                            href={application.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-sm text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {application.url.replace('https://', '')}
                                        </a>
                                    )}
                                </div>
                                <DeploymentBadge status={application.status} />
                                <span className="text-muted-foreground w-24 text-sm sm:text-right">{timeAgo(application.deployed_at)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
