import { DeploymentBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type EnvironmentPageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const tabs = [
    { key: 'overview', title: 'Overview', route: 'environments.show' },
    { key: 'deployments', title: 'Deployments', route: 'environments.deployments' },
    { key: 'logs', title: 'Logs', route: 'environments.logs' },
    { key: 'env', title: 'Environment values', route: 'environments.env' },
    { key: 'domains', title: 'Domains', route: 'environments.domains' },
    { key: 'settings', title: 'Settings', route: 'environments.settings' },
] as const;

export type EnvironmentTab = (typeof tabs)[number]['key'];

export default function EnvironmentLayout({
    page,
    tab,
    title,
    children,
}: {
    page: EnvironmentPageProps;
    tab: EnvironmentTab | null;
    title: string;
    children: React.ReactNode;
}) {
    const { application, environment, can } = page;
    const errors = usePage().props.errors as Record<string, string>;
    const [deploying, setDeploying] = useState(false);

    const deploy = () => {
        router.post(route('deployments.store', environment.id), {}, { onStart: () => setDeploying(true), onFinish: () => setDeploying(false) });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Applications', href: route('applications.index') },
                { title: application.name, href: route('applications.show', application.id) },
                { title: environment.name, href: route('environments.show', environment.id) },
            ]}
        >
            <Head title={`${title} - ${application.name} ${environment.name}`} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {application.name} <span className="text-muted-foreground font-normal">/ {environment.name}</span>
                            </h1>
                            <DeploymentBadge status={environment.status} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {application.repository}
                            {environment.branch && <> on branch {environment.branch}</>}
                        </p>
                    </div>

                    {can.deploy && environment.kind !== 'preview_template' && (
                        <Button onClick={deploy} disabled={deploying}>
                            {deploying ? 'Starting' : 'Deploy'}
                        </Button>
                    )}
                </div>

                {errors.deploy && <p className="text-sm text-red-600 dark:text-red-400">{errors.deploy}</p>}

                <nav className="flex gap-1 overflow-x-auto overflow-y-hidden border-b [scrollbar-width:none]" aria-label="Environment sections">
                    {tabs.map((item) => (
                        <Link
                            key={item.key}
                            href={route(item.route, environment.id)}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                                item.key === tab
                                    ? 'border-primary text-foreground font-medium'
                                    : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            {item.title}
                        </Link>
                    ))}
                </nav>

                <div className="space-y-6 pt-2">{children}</div>
            </div>
        </AppLayout>
    );
}
