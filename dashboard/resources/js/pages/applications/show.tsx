import HeadingSmall from '@/components/heading-small';
import { DeploymentBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { applicationTypeLabels, type ApplicationType, type DeploymentStatus, type EnvironmentKind } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { NewEnvironment } from './new-environment';

type EnvironmentRow = {
    id: number;
    name: string;
    kind: EnvironmentKind;
    is_protected: boolean;
    branch: string | null;
    pr_number: number | null;
    pr_url: string | null;
    status: DeploymentStatus | null;
    url: string | null;
};

type Props = {
    application: { id: number; name: string; repository: string; type: ApplicationType; port: number };
    environments: EnvironmentRow[];
    previews: { previews_enabled: boolean; max_previews: number };
    canUpdate: boolean;
};

function PullRequest({ environment }: { environment: EnvironmentRow }) {
    const label = `Pull request #${environment.pr_number}`;

    if (!environment.pr_url) {
        return <>{label}</>;
    }

    return (
        <a href={environment.pr_url} target="_blank" rel="noopener noreferrer" className="hover:underline">
            {label}
        </a>
    );
}

export default function ApplicationShow({ application, environments, previews, canUpdate }: Props) {
    const [saving, setSaving] = useState(false);

    const setPreviews = (enabled: boolean) =>
        router.patch(
            route('applications.previews.update', application.id),
            { previews_enabled: enabled },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );

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
                    <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm">
                        <span>{application.repository}</span>
                        <Badge
                            variant="secondary"
                            title="The preset is fixed. It also sets the env names that the databases, Valkey, and the buckets give the app."
                        >
                            {applicationTypeLabels[application.type]}
                        </Badge>
                    </div>
                </div>

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <HeadingSmall
                        title="Environments"
                        description="Production, an environment for each other branch that you add, and the previews."
                    />
                    {canUpdate && <NewEnvironment applicationId={application.id} />}
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
                                    {environment.kind === 'preview_template' && <Badge variant="outline">Preview template</Badge>}
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {environment.kind === 'preview' ? (
                                        <PullRequest environment={environment} />
                                    ) : environment.kind === 'preview_template' ? (
                                        `Each new preview copies its settings, processes, and env values. At most ${previews.max_previews} previews run at the same time.`
                                    ) : (
                                        `Branch ${environment.branch}`
                                    )}
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
                            {environment.kind === 'preview_template' ? (
                                <Label
                                    className="text-muted-foreground flex items-center gap-2 text-sm font-normal"
                                    title="Create a preview for each pull request"
                                >
                                    Previews
                                    <Switch checked={previews.previews_enabled} disabled={!canUpdate || saving} onCheckedChange={setPreviews} />
                                </Label>
                            ) : (
                                <DeploymentBadge status={environment.status} />
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
