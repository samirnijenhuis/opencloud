import { Chip } from '@/components/chip';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type AppReach, type RepositoryAccess, type RepositoryRead } from '@/types/repository-access';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type Cluster = { id: number; name: string; kind: string; ingress_ip: string | null };
type DeployKeyChoice = 'none' | 'new' | 'own';

type FormData = {
    repository: string;
    branch: string;
    deploy_key: DeployKeyChoice;
    deploy_private_key: string;
    cluster_id: string;
};

const readTone: Record<RepositoryRead, string> = {
    github_app: 'text-green-700 dark:text-green-400',
    deploy_key: 'text-green-700 dark:text-green-400',
    public: 'text-green-700 dark:text-green-400',
    branch_missing: 'text-amber-700 dark:text-amber-400',
    key_refused: 'text-red-700 dark:text-red-400',
    unreadable: 'text-red-700 dark:text-red-400',
};

/**
 * What the GitHub App gives this repository, and the step that connects it. RepositoryAccess::summary() says only how opencloud reads it.
 * The app is private, so GitHub installs it only on the account of its owner.
 */
const appReach: Record<AppReach, { sentence: (access: RepositoryAccess) => string | null; step: 'install' | 'set_up' | null }> = {
    installed: { sentence: () => 'Push deploys and previews work.', step: null },
    not_installed: { sentence: () => 'Push deploys and previews need the GitHub App.', step: 'install' },
    no_app: { sentence: () => 'Push deploys and previews need the GitHub App.', step: 'set_up' },
    other_account: {
        sentence: (access) =>
            `The GitHub App belongs to ${access.app_owner}. GitHub lets a private app reach only the repositories of ${access.app_owner}.`,
        step: null,
    },
    not_github: { sentence: () => null, step: null },
    unknown: { sentence: () => 'Opencloud could not ask GitHub about the GitHub App.', step: 'install' },
};

function Row({ label, hint, htmlFor, children }: { label: string; hint?: string; htmlFor?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2 border-b py-5 sm:grid-cols-[11rem_1fr] sm:gap-6">
            <div>
                <Label htmlFor={htmlFor} className="text-sm font-medium">
                    {label}
                </Label>
                {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
            </div>
            <div className="min-w-0 space-y-2">{children}</div>
        </div>
    );
}

function NewTabLink({ href, children }: { href: string; children: React.ReactNode }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 font-medium underline-offset-4 hover:underline"
        >
            {children}
            <ExternalLink className="size-3" />
        </a>
    );
}

/** The next step for the GitHub App. GitHub opens in a new tab, and the check runs again when this tab gets focus. */
function AppStep({ access, canSetUpApp }: { access: RepositoryAccess; canSetUpApp: boolean }) {
    const step = appReach[access.app].step;

    if (step === 'install' && access.install_url) {
        return <NewTabLink href={access.install_url}>Install on GitHub</NewTabLink>;
    }

    if (step === 'set_up' && canSetUpApp) {
        return <NewTabLink href={route('github.index')}>Set up the GitHub App</NewTabLink>;
    }

    return null;
}

export default function ApplicationCreate({ clusters, defaultClusterId }: { clusters: Cluster[]; defaultClusterId: number | null }) {
    const { can } = usePage<SharedData>().props;
    const form = useForm<FormData>({
        repository: '',
        branch: '',
        deploy_key: 'none',
        deploy_private_key: '',
        cluster_id: defaultClusterId ? String(defaultClusterId) : '',
    });
    const { data, setData, errors, processing } = form;
    const [wantsKey, setWantsKey] = useState(false);

    // Each check can run git and call GitHub, so the page asks only when the user stops typing.
    const [checked, setChecked] = useState('');
    useEffect(() => {
        const timer = window.setTimeout(() => setChecked(data.repository.trim()), 500);

        return () => window.clearTimeout(timer);
    }, [data.repository]);

    const [polled] = usePoll<RepositoryAccess>(
        checked === '' ? null : route('applications.repository-access.check', { repository: checked }),
        10_000,
        (access) => !access.read_works,
    );
    const access = checked === data.repository.trim() ? polled : null;
    const defaultBranch = access?.default_branch ?? null;
    const unreadableOnGitHub = access?.read === 'unreadable' && access.github_repository !== null;
    const appSentence =
        access && (access.read_works || (unreadableOnGitHub && access.app === 'other_account')) ? appReach[access.app].sentence(access) : null;
    const keyChipsBelow = !unreadableOnGitHub && (data.deploy_key !== 'none' || (wantsKey && access?.github_repository != null));

    const chooseKey = (choice: Exclude<DeployKeyChoice, 'none'>) => setData('deploy_key', data.deploy_key === choice ? 'none' : choice);

    const keyChips = (
        <div className="flex flex-wrap gap-2">
            <Chip selected={data.deploy_key === 'new'} onClick={() => chooseKey('new')}>
                Make a key
            </Chip>
            <Chip selected={data.deploy_key === 'own'} onClick={() => chooseKey('own')}>
                Use my own key
            </Chip>
        </div>
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((current) => ({
            ...current,
            // Only the Laravel preset is offered for now.
            type: 'laravel',
            port: null,
            branch: current.branch || defaultBranch || '',
            deploy_key: current.deploy_key === 'new',
            deploy_private_key: current.deploy_key === 'own' ? current.deploy_private_key : null,
        }));
        form.post(route('applications.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Applications', href: route('applications.index') },
                { title: 'New application', href: route('applications.create') },
            ]}
        >
            <Head title="New application" />

            <form onSubmit={submit} className="mx-auto w-full max-w-3xl p-4">
                <h1 className="text-2xl font-semibold tracking-tight">New application</h1>
                <p className="text-muted-foreground mt-1 text-sm">
                    Choose the repository and the cluster. You set the size, the processes, the database, and the env values on the environment page.
                    Nothing deploys until you select Deploy there.
                </p>

                <div className="mt-4">
                    <Row label="Repository" hint="owner/repo, git@github.com:owner/repo.git, or a full https address">
                        <div className="flex gap-2">
                            <Input
                                aria-label="Repository"
                                className="flex-1"
                                placeholder="samir/my-shop"
                                value={data.repository}
                                onChange={(e) => setData('repository', e.target.value)}
                                autoFocus
                            />
                            <Input
                                aria-label="Branch"
                                className="w-32"
                                placeholder={defaultBranch ?? (data.deploy_key === 'new' ? 'main' : 'default branch')}
                                value={data.branch}
                                onChange={(e) => setData('branch', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.repository} />
                        <InputError message={errors.branch} />

                        <div aria-live="polite">
                            {access && (
                                <div className="space-y-2 text-sm">
                                    <p>
                                        <span className={cn('font-medium', readTone[access.read])}>{access.summary}</span>
                                        {appSentence && <span className="text-muted-foreground"> {appSentence}</span>}
                                        {unreadableOnGitHub && access.app === 'other_account' && (
                                            <span className="text-muted-foreground"> Use a deploy key for {access.github_repository}.</span>
                                        )}
                                        {access.read_works && (
                                            <>
                                                {' '}
                                                <AppStep access={access} canSetUpApp={can.manage_credentials} />
                                            </>
                                        )}
                                    </p>

                                    {unreadableOnGitHub && (
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {appReach[access.app].step !== null && (
                                                <div className="space-y-2 rounded-md border p-3">
                                                    <p>
                                                        <span className="font-medium">GitHub App.</span>{' '}
                                                        <span className="text-muted-foreground">Builds, push deploys, and previews.</span>
                                                    </p>
                                                    <AppStep access={access} canSetUpApp={can.manage_credentials} />
                                                </div>
                                            )}
                                            <div className="space-y-2 rounded-md border p-3">
                                                <p>
                                                    <span className="font-medium">Deploy key.</span>{' '}
                                                    <span className="text-muted-foreground">Builds only.</span>
                                                </p>
                                                {keyChips}
                                            </div>
                                        </div>
                                    )}

                                    {access.read_works && access.github_repository !== null && !keyChipsBelow && (
                                        <button
                                            type="button"
                                            className="text-muted-foreground hover:text-foreground text-xs"
                                            onClick={() => setWantsKey(true)}
                                        >
                                            Use a deploy key
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        {keyChipsBelow && keyChips}
                        {data.deploy_key === 'new' && (
                            <p className="text-muted-foreground text-sm">
                                Opencloud makes a deploy key. The environment page then shows the key and the steps to add it on GitHub. Add it before
                                the first deploy.
                            </p>
                        )}
                        {data.deploy_key === 'own' && (
                            <>
                                <Textarea
                                    aria-label="Private key"
                                    className="font-mono text-xs"
                                    rows={6}
                                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={data.deploy_private_key}
                                    onChange={(e) => setData('deploy_private_key', e.target.value)}
                                />
                                <p className="text-muted-foreground text-sm">
                                    Paste the private key file of a key that GitHub already knows, for example ~/.ssh/id_ed25519. The key must not
                                    have a passphrase. The page never shows the key again.
                                </p>
                            </>
                        )}
                        <InputError message={errors.deploy_key} />
                        <InputError message={errors.deploy_private_key} />
                    </Row>

                    <Row label="Cluster">
                        <Select value={data.cluster_id} onValueChange={(value) => setData('cluster_id', value)}>
                            <SelectTrigger className="w-full sm:w-72" aria-label="Cluster">
                                <SelectValue placeholder="Choose a cluster" />
                            </SelectTrigger>
                            <SelectContent>
                                {clusters.map((cluster) => (
                                    <SelectItem key={cluster.id} value={String(cluster.id)}>
                                        {cluster.name}
                                        {cluster.kind === 'local' && ' (dashboard server)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cluster_id} />
                    </Row>
                </div>

                <div className="mt-6 flex items-center gap-3">
                    <Button
                        disabled={processing || data.repository.trim() === '' || (data.deploy_key === 'own' && data.deploy_private_key.trim() === '')}
                    >
                        {processing ? 'Creating' : 'Create application'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('applications.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
