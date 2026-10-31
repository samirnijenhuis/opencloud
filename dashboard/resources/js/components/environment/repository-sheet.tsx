import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import {
    type AppReach,
    type EnvironmentRepositoryAccess,
    type PreviewDelivery,
    type PushDelivery,
    type RepositoryRead,
} from '@/types/repository-access';
import { Link, router, useForm } from '@inertiajs/react';
import { Copy, ExternalLink } from 'lucide-react';
import { createContext, type FormEventHandler, type ReactNode, useContext, useState } from 'react';
import { Status, type Tone } from './block';
import { EditorSheet } from './editor-sheet';

type Access = EnvironmentRepositoryAccess;

/** The layout reads the repository access once for every tab. The canvas gets the same answer through this context. */
export const RepositoryAccessContext = createContext<{ access: Access | null; refresh: () => void }>({ access: null, refresh: () => {} });

export const useRepositoryAccess = () => useContext(RepositoryAccessContext);

const buildStates: Record<RepositoryRead, [Tone, string]> = {
    github_app: ['ok', 'Through the GitHub App'],
    deploy_key: ['ok', 'Through the deploy key'],
    public: ['ok', 'Through the public URL'],
    branch_missing: ['bad', 'Branch missing'],
    key_refused: ['warn', 'Waiting for the key'],
    unreadable: ['bad', 'No access'],
};

const pushStates: Record<PushDelivery, [Tone, string]> = {
    github_app: ['ok', 'Through the GitHub App'],
    deploy_hook: ['ok', 'Through the deploy hook'],
    not_connected: ['warn', 'Not connected'],
    off: ['off', 'Off'],
};

const pushSentences: Record<PushDelivery, string> = {
    github_app: 'The GitHub App sends each push to the branch, and opencloud deploys it.',
    deploy_hook: 'A push deploys when a webhook on GitHub calls the deploy hook.',
    not_connected: 'Auto deploy is on, but no push reaches opencloud. Connect the GitHub App or the deploy hook.',
    off: 'Auto deploy and the deploy hook are off. Deploy from this page.',
};

export const previewStates: Record<PreviewDelivery, [Tone, string]> = {
    on: ['ok', 'On'],
    not_connected: ['warn', 'Not connected'],
    off: ['off', 'Off'],
};

const appStates: Record<AppReach, [Tone, string]> = {
    installed: ['ok', 'Installed'],
    not_installed: ['warn', 'Not installed'],
    other_account: ['off', 'Other account'],
    no_app: ['off', 'Not set up'],
    not_github: ['off', 'Not available'],
    unknown: ['warn', 'Unknown'],
};

/** A refused key does not fall back to the plain URL, so a public repository with a refused key still fails. */
const publicSentences: Record<RepositoryRead, string> = {
    github_app: 'Not needed. The GitHub App reads the repository.',
    deploy_key: 'Not needed. The deploy key reads the repository.',
    public: 'Opencloud reads the repository with no key and no app.',
    branch_missing: 'Opencloud reads the repository. Only the branch is wrong.',
    key_refused: 'Opencloud always reads with the deploy key first. Remove the key to read a public repository with no key.',
    unreadable: 'Make the repository public on GitHub. Opencloud then reads it with no key.',
};

const repositoryName = (access: Access) => access.github_repository ?? 'this repository';

function appSentence(access: Access): string {
    const sentences: Record<AppReach, string> = {
        installed: `The GitHub App is installed on ${repositoryName(access)}.`,
        not_installed: `The GitHub App is not installed on ${repositoryName(access)}.`,
        other_account: `The GitHub App belongs to ${access.app_owner}. GitHub lets a private app reach only the repositories of ${access.app_owner}.`,
        no_app: 'Opencloud has no GitHub App yet.',
        not_github: 'The GitHub App works only for a repository on github.com.',
        unknown: 'GitHub did not answer the check of the GitHub App.',
    };

    return sentences[access.app];
}

/** What reaches opencloud from a pull request. The previews sheet starts with it when no pull request can arrive. */
export function previewSentence(access: Access): string {
    const sentences: Record<PreviewDelivery, string> = {
        on: 'The GitHub App sends each pull request, and opencloud opens a preview for it.',
        not_connected: `No pull request reaches opencloud. ${appSentence(access)}`,
        off: 'Previews are off. Turn them on for the preview template on the application page.',
    };

    return sentences[access.previews];
}

function ExternalButton({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Button asChild variant="secondary" size="sm">
            <a href={href} target="_blank" rel="noopener noreferrer">
                {children}
                <ExternalLink />
            </a>
        </Button>
    );
}

function Item({ label, aside, state, children }: { label: string; aside?: string; state?: [Tone, string]; children: ReactNode }) {
    return (
        <li className="space-y-2 px-3 py-3">
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <div>
                    <p className="font-medium">{label}</p>
                    {aside && <p className="text-muted-foreground">{aside}</p>}
                </div>
                {state && <Status tone={state[0]}>{state[1]}</Status>}
            </div>
            {children}
        </li>
    );
}

type Change = 'replace' | 'remove';

const confirmations: Record<Change, { title: string; description: string; button: string }> = {
    replace: {
        title: 'Make a new deploy key?',
        description:
            'Opencloud uses the new key at once, and GitHub refuses it until you add it. Add the new key on GitHub, and delete the old key there.',
        button: 'Make a new key',
    },
    remove: {
        title: 'Remove the deploy key?',
        description:
            'Opencloud then reads the repository without a key. A private repository then needs the GitHub App. Delete the key on GitHub too.',
        button: 'Remove',
    },
};

/** Opencloud reads with the key whenever one is set, so a missing branch also proves that GitHub accepts the key. */
const keyAccepted = (read: RepositoryRead) => read === 'deploy_key' || read === 'branch_missing';

function DeployKeyItem({ access, applicationId, onChange }: { access: Access; applicationId: number; onChange: () => void }) {
    const [copied, setCopied] = useState(false);
    const [confirming, setConfirming] = useState<Change | null>(null);
    const [busy, setBusy] = useState(false);
    const [pasting, setPasting] = useState(false);
    const ownKey = useForm({ deploy_private_key: '' });
    const publicKey = access.deploy_public_key;
    const accepted = keyAccepted(access.read);

    const closeOwnKey = () => {
        ownKey.reset();
        ownKey.clearErrors();
        setPasting(false);
    };

    const saveOwnKey: FormEventHandler = (e) => {
        e.preventDefault();
        ownKey.post(route('applications.deploy-key.store', applicationId), {
            preserveScroll: true,
            onSuccess: () => {
                closeOwnKey();
                onChange();
            },
        });
    };

    const send = (change: 'add' | Change) =>
        router.visit(route(change === 'remove' ? 'applications.deploy-key.destroy' : 'applications.deploy-key.store', applicationId), {
            method: change === 'remove' ? 'delete' : 'post',
            preserveScroll: true,
            onStart: () => setBusy(true),
            onSuccess: onChange,
            onFinish: () => {
                setBusy(false);
                setConfirming(null);
            },
        });

    const copy = async (text: string) => {
        await navigator.clipboard.writeText(text);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    const ownKeyButton = !pasting && (
        <Button variant="secondary" size="sm" disabled={busy} onClick={() => setPasting(true)}>
            Use my own key
        </Button>
    );

    if (access.app === 'not_github') {
        return (
            <Item label="Deploy key" aside="Builds only" state={['off', 'Not available']}>
                <p>A deploy key works only for a repository on github.com.</p>
            </Item>
        );
    }

    return (
        <Item
            label="Deploy key"
            aside="Builds only"
            state={publicKey === null ? ['off', 'None'] : accepted ? ['ok', 'Accepted'] : ['warn', 'Waiting for GitHub']}
        >
            {publicKey === null ? (
                <>
                    <p>A deploy key lets opencloud read a private repository on GitHub. The key can only read.</p>
                    {access.can_update && (
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" disabled={busy} onClick={() => send('add')}>
                                Make a key
                            </Button>
                            {ownKeyButton}
                        </div>
                    )}
                </>
            ) : (
                <>
                    <div className="flex items-center gap-1 rounded-md border">
                        <code className="min-w-0 flex-1 truncate px-3 py-2 font-mono text-xs" title={publicKey}>
                            {publicKey}
                        </code>
                        <Button variant="ghost" size="icon" aria-label="Copy the public key" onClick={() => copy(publicKey)}>
                            <Copy />
                        </Button>
                    </div>
                    {copied && <p className="text-muted-foreground text-xs">Copied.</p>}

                    {!accepted && (
                        <>
                            <ol className="list-decimal space-y-1 pl-5">
                                <li>Copy the key.</li>
                                <li>
                                    Open{' '}
                                    <a
                                        href={`https://github.com/${access.github_repository}/settings/keys/new`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 font-medium underline"
                                    >
                                        Add deploy key on GitHub
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                    .
                                </li>
                                <li>Paste the key. Keep Allow write access off.</li>
                                <li>Select Add key.</li>
                            </ol>
                            <p className="text-muted-foreground">Waiting for GitHub. This page checks again every 10 seconds.</p>
                        </>
                    )}

                    {access.can_update && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="secondary" size="sm" disabled={busy} onClick={() => setConfirming('replace')}>
                                New key
                            </Button>
                            {ownKeyButton}
                            <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming('remove')}>
                                Remove
                            </Button>
                        </div>
                    )}
                </>
            )}

            {access.can_update && pasting && (
                <form onSubmit={saveOwnKey} className="space-y-2">
                    <Textarea
                        aria-label="Private key"
                        className="font-mono text-xs"
                        rows={6}
                        placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                        autoComplete="off"
                        spellCheck={false}
                        value={ownKey.data.deploy_private_key}
                        onChange={(e) => ownKey.setData('deploy_private_key', e.target.value)}
                    />
                    <p className="text-muted-foreground">
                        Paste the private key file, for example ~/.ssh/id_ed25519. The key must not have a passphrase.
                        {publicKey !== null && ' It replaces the current key at once.'} The page never shows the key again.
                    </p>
                    <InputError message={ownKey.errors.deploy_private_key} />
                    <div className="flex gap-2">
                        <Button size="sm" disabled={ownKey.processing || ownKey.data.deploy_private_key.trim() === ''}>
                            Save
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={closeOwnKey}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}

            <Dialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
                {confirming && (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{confirmations[confirming].title}</DialogTitle>
                            <DialogDescription>{confirmations[confirming].description}</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="secondary" onClick={() => setConfirming(null)}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={busy} onClick={() => send(confirming)}>
                                {confirmations[confirming].button}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </Item>
    );
}

export function RepositorySheet({
    open,
    onClose,
    access,
    refresh,
    applicationId,
    environmentId,
}: {
    open: boolean;
    onClose: () => void;
    access: Access | null;
    refresh: () => void;
    applicationId: number;
    environmentId: number;
}) {
    const settings = (section: string) => `${route('environments.settings', environmentId)}#${section}`;

    if (access === null) {
        return (
            <EditorSheet open={open} onClose={onClose} title="Repository" description="How opencloud reads the repository and what starts a deploy.">
                <p className="text-muted-foreground text-sm">Checking the repository.</p>
            </EditorSheet>
        );
    }

    const appSteps: Record<AppReach, ReactNode> = {
        installed: null,
        not_installed: access.install_url && (
            <>
                <ExternalButton href={access.install_url}>Install on GitHub</ExternalButton>
                <p className="text-muted-foreground">This page checks again when you come back to this tab.</p>
            </>
        ),
        other_account: <p>Use a deploy key for {repositoryName(access)}.</p>,
        no_app: access.can_manage_credentials ? (
            <ExternalButton href={route('github.index')}>Set up the GitHub App</ExternalButton>
        ) : (
            <p className="text-muted-foreground">Only an owner can set up the GitHub App.</p>
        ),
        not_github: null,
        unknown: null,
    };

    const turnOnHook = (
        <>
            Turn on the deploy hook in{' '}
            <Link href={settings('deployments')} className="font-medium underline">
                Settings
            </Link>
            . Then add its URL on GitHub as a webhook, under Settings, Webhooks.
        </>
    );
    const hookSteps: Record<PushDelivery, ReactNode> = {
        github_app: 'Not needed. The GitHub App sends each push.',
        deploy_hook: 'Add its URL on GitHub as a webhook, under Settings, Webhooks.',
        not_connected: turnOnHook,
        off: turnOnHook,
    };

    return (
        <EditorSheet
            open={open}
            onClose={onClose}
            title="Repository"
            description={`How opencloud reads ${access.github_repository ?? access.repository} and what starts a deploy.`}
        >
            <section className="space-y-3">
                <h3 className="text-sm font-medium">What works now</h3>
                <ul className="divide-y rounded-lg border text-sm">
                    <Item label="Builds" state={buildStates[access.read]}>
                        <p>
                            {access.summary}
                            {access.read === 'branch_missing' && (
                                <>
                                    {' '}
                                    <Link href={settings('general')} className="font-medium underline">
                                        Change the branch in Settings
                                    </Link>
                                    .
                                </>
                            )}
                        </p>
                    </Item>
                    {access.push && (
                        <Item label="Deploy on push" state={pushStates[access.push]}>
                            <p>{pushSentences[access.push]}</p>
                        </Item>
                    )}
                    <Item label="Previews" state={previewStates[access.previews]}>
                        <p>{previewSentence(access)}</p>
                    </Item>
                </ul>
            </section>

            <section className="space-y-3">
                <h3 className="text-sm font-medium">Ways to connect</h3>
                <ul className="divide-y rounded-lg border text-sm">
                    <Item label="GitHub App" aside="Builds, push deploys, and previews" state={appStates[access.app]}>
                        <p>{appSentence(access)}</p>
                        {appSteps[access.app]}
                    </Item>
                    <DeployKeyItem access={access} applicationId={applicationId} onChange={refresh} />
                    {access.push && (
                        <Item
                            label="Deploy hook"
                            aside="Push deploys from a webhook"
                            state={access.push === 'deploy_hook' ? ['ok', 'On'] : undefined}
                        >
                            <p>{hookSteps[access.push]}</p>
                        </Item>
                    )}
                    <Item label="Public repository" aside="Builds" state={access.read === 'public' ? ['ok', 'In use'] : undefined}>
                        <p>{publicSentences[access.read]}</p>
                    </Item>
                </ul>
            </section>
        </EditorSheet>
    );
}
