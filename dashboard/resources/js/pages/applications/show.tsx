import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { DeploymentBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { applicationTypeLabels, type ApplicationType, type DeploymentStatus, type EnvironmentKind } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { DeployKey } from './deploy-key';
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

type PreviewSettings = {
    previews_enabled: boolean;
    preview_domain: string | null;
    preview_labels: string[];
    ingress_ip: string | null;
    max_previews: number;
    template_id: number | null;
};

type Props = {
    application: { id: number; name: string; repository: string; type: ApplicationType; port: number };
    environments: EnvironmentRow[];
    previews: PreviewSettings;
    deployPublicKey: string | null;
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

function PreviewsForm({ application, previews, canUpdate }: { application: Props['application']; previews: PreviewSettings; canUpdate: boolean }) {
    const form = useForm({
        previews_enabled: previews.previews_enabled,
        preview_domain: previews.preview_domain ?? '',
        preview_labels: previews.preview_labels.join(', '),
    });
    const { data, setData, errors, processing, recentlySuccessful } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('applications.previews.update', application.id), { preserveScroll: true });
    };

    const example = data.preview_domain.trim() || 'preview.example.com';
    const domainHelp =
        `Add one DNS record *.${example} that points to ${previews.ingress_ip ?? 'the cluster'}. ` +
        `Each preview then gets an address like pr-12.${example}. Leave it empty to use a free sslip.io address.`;

    return (
        <form onSubmit={submit} className="space-y-4 rounded-lg border p-4">
            <fieldset disabled={!canUpdate} className="space-y-4">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox checked={data.previews_enabled} onCheckedChange={(checked) => setData('previews_enabled', checked === true)} />
                    Create a preview for each pull request
                </Label>

                <div className="grid gap-2">
                    <Label htmlFor="preview_domain">Preview domain</Label>
                    <Input
                        id="preview_domain"
                        className="max-w-sm"
                        value={data.preview_domain}
                        onChange={(e) => setData('preview_domain', e.target.value)}
                        placeholder="preview.example.com"
                    />
                    <p className="text-muted-foreground text-sm">{domainHelp}</p>
                    <InputError message={errors.preview_domain} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="preview_labels">Only pull requests with these labels</Label>
                    <Input
                        id="preview_labels"
                        className="max-w-sm"
                        value={data.preview_labels}
                        onChange={(e) => setData('preview_labels', e.target.value)}
                        placeholder="preview"
                    />
                    <p className="text-muted-foreground text-sm">
                        Separate the labels with commas. A pull request gets a preview when it has one of the labels, and loses the preview when it
                        loses the last one. Leave it empty to give each pull request a preview.
                    </p>
                    <InputError message={errors.preview_labels} />
                </div>
            </fieldset>

            {canUpdate && (
                <div className="flex items-center gap-3">
                    <Button disabled={processing}>Save</Button>
                    {recentlySuccessful && <span className="text-muted-foreground text-sm">Saved</span>}
                </div>
            )}
        </form>
    );
}

export default function ApplicationShow({ application, environments, previews, deployPublicKey, canUpdate }: Props) {
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
                        {application.repository} - {applicationTypeLabels[application.type]}
                    </p>
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
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {environment.kind === 'preview' ? <PullRequest environment={environment} /> : `Branch ${environment.branch}`}
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

                {/* The controller sorts production first. */}
                <DeployKey
                    applicationId={application.id}
                    publicKey={deployPublicKey}
                    settingsUrl={`${route('environments.settings', environments[0].id)}#deployments`}
                    canUpdate={canUpdate}
                />

                <div className="space-y-4">
                    <HeadingSmall
                        title="Preset"
                        description="The preset is fixed. It also sets the env names that the databases, Valkey, and the buckets give the app."
                    />
                    <div className="space-y-1 rounded-lg border p-4">
                        <p className="font-medium">{applicationTypeLabels[application.type]}</p>
                        <p className="text-muted-foreground text-sm">To use another preset, create a new application with the same repository.</p>
                    </div>
                </div>

                <div className="space-y-4">
                    <HeadingSmall
                        title="Previews"
                        description={`Each pull request gets its own environment with an empty database. At most ${previews.max_previews} previews run at the same time.`}
                    />

                    {previews.template_id && (
                        <p className="text-sm">
                            Previews copy the settings and the environment values of the{' '}
                            <Link
                                href={`${route('environments.settings', previews.template_id)}#environment-values`}
                                className="font-medium hover:underline"
                            >
                                preview template
                            </Link>
                            . Production values never go to a preview.
                        </p>
                    )}

                    <PreviewsForm application={application} previews={previews} canUpdate={canUpdate} />
                </div>
            </div>
        </AppLayout>
    );
}
