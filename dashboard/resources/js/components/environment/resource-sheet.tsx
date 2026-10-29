import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { AddResourceForm, needsDialog, privateHelp } from './add-resource';
import { Status, type Tone } from './block';
import { EditorSheet, NextDeployFooter } from './editor-sheet';
import {
    type Attachment,
    type BackupRow,
    type DatastoreKind,
    type DatastoreSource,
    type DatastoreStatus,
    type Datastores,
    type Offer,
    type ResourceFamily,
} from './types';

export const statusLabels: Record<DatastoreStatus, string> = {
    pending: 'Pending',
    provisioning: 'Provisioning',
    ready: 'Ready',
    failed: 'Failed',
    destroying: 'Destroying',
};

export const statusTones: Record<DatastoreStatus, Tone> = {
    pending: 'off',
    provisioning: 'warn',
    ready: 'ok',
    failed: 'bad',
    destroying: 'warn',
};

const backupStatusLabels = { running: 'Running', done: 'Done', failed: 'Failed' } as const;

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;

    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

const attach = (environmentId: number, kind: DatastoreKind, source: DatastoreSource = 'standard', name: string | null = null, isPublic = false) => {
    router.post(route('datastores.store', environmentId), { kind, source, name, public: isPublic }, { preserveScroll: true });
};

/** What the sheet says about each source. The backend gives the names, so no provider name appears here. */
const sourceTexts: Record<
    DatastoreSource,
    {
        where: (attachment: Attachment) => string;
        verb: 'Detach' | 'Destroy';
        detach: (attachment: Attachment) => string;
        confirm: (attachment: Attachment) => string;
    }
> = {
    standard: {
        where: (attachment) => `Runs in the cluster ${attachment.runs_on}.`,
        verb: 'Detach',
        detach: () => 'The app loses access. The data stays on the server.',
        confirm: (attachment) =>
            `The app loses its access and the env values it set are removed. The data stays on the server. ${attachment.name ? `Attach a ${attachment.family} with the same kind and name again to use it.` : 'Attach the same kind again to use it.'}`,
    },
    managed: {
        where: (attachment) => `${attachment.runs_on} runs it for this environment.`,
        verb: 'Destroy',
        detach: (attachment) => `OpenTofu destroys it at ${attachment.runs_on}, with its data and backups.`,
        confirm: (attachment) =>
            `OpenTofu destroys it at ${attachment.runs_on}, and ${attachment.runs_on} deletes the data and its backups. You cannot undo this. The env values it set are removed now.`,
    },
    external: {
        where: (attachment) => `A server that you run at ${attachment.runs_on}.`,
        verb: 'Detach',
        detach: () => 'The app loses access. Opencloud does not touch the data on your server.',
        confirm: () => 'The app loses its access and the env values it set are removed. Opencloud does not touch the data on your server.',
    },
};

/** What the sheet says about the name and the default of each family with names. */
const namedTexts: Record<
    Exclude<ResourceFamily, 'key_value'>,
    {
        heading: string;
        isDefault: string;
        keepsTheDefault: string;
        configHint: (name: string) => string;
        noConfigLine: string;
        oldDefault: (name: string) => string;
    }
> = {
    database: {
        heading: 'Connection',
        isDefault: 'This is the default database. The default env values point to it.',
        keepsTheDefault: 'This is the default database. Make another database the default first.',
        configHint: (name) => `Add this line to the connections in config/database.php. Then use DB::connection('${name}') in the app.`,
        noConfigLine: 'The app reaches this database through its URL value.',
        oldDefault: (name) => `${name} gets one URL value instead, and the app needs a line in its database config to use it.`,
    },
    bucket: {
        heading: 'Bucket',
        isDefault: 'This is the default bucket. The default env values point to it.',
        keepsTheDefault: 'This is the default bucket. Make another bucket the default first.',
        configHint: (name) => `Add this line to the disks in config/filesystems.php. Then use Storage::disk('${name}') in the app.`,
        noConfigLine: 'The app reaches this bucket through its env values with its name as a prefix.',
        oldDefault: (name) =>
            `${name} gets env values with the prefix ${name.toUpperCase()}_ instead, and the app needs a disk in config/filesystems.php to use it.`,
    },
};

/** The texts of a database or a bucket, or null for a key-value store, which has no name. */
export const namedTextsFor = (attachment: Attachment) => (attachment.family === 'key_value' ? null : namedTexts[attachment.family]);

type OfferGroup = { source: DatastoreSource; heading: string; offers: Offer[]; refusal: string | null };

/** The offers of each source in the order of the backend. A group whose offers all share one refusal shows it once. */
function groupBySource(offers: Offer[]): OfferGroup[] {
    const sources = [...new Set(offers.map((offer) => offer.source))];

    return sources.map((source) => {
        const members = offers.filter((offer) => offer.source === source);
        const refusals = new Set(members.map((offer) => offer.refusal));

        return { source, heading: members[0].group, offers: members, refusal: members.length > 1 && refusals.size === 1 ? members[0].refusal : null };
    });
}

function CopyLine({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(text);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="flex items-start gap-1 rounded-md border">
            <code className="min-w-0 flex-1 px-3 py-2 font-mono text-xs break-words whitespace-pre-wrap">{text}</code>
            <Button type="button" variant="ghost" size="icon" aria-label={copied ? 'Copied' : 'Copy the line'} onClick={copy}>
                {copied ? <Check /> : <Copy />}
            </Button>
        </div>
    );
}

/** What the backend registry offers to this environment and this user, by source. The menu holds no list of its own. */
export function AddResourceMenu({ environmentId, datastores, children }: { environmentId: number; datastores: Datastores; children: ReactNode }) {
    const [choice, setChoice] = useState<Offer | null>(null);

    const pick = (offer: Offer) => (needsDialog(offer) ? setChoice(offer) : attach(environmentId, offer.kind, offer.source));

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>{children}</DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="max-h-[70vh] w-80 overflow-y-auto">
                    {groupBySource(datastores.offers).map((group) => (
                        <DropdownMenuGroup key={group.source}>
                            <DropdownMenuLabel className="text-muted-foreground text-xs">{group.heading}</DropdownMenuLabel>
                            {group.refusal !== null ? (
                                <p className="text-muted-foreground px-2 pb-2 text-xs">{group.refusal}</p>
                            ) : (
                                group.offers.map((offer) => (
                                    <DropdownMenuItem
                                        key={offer.kind}
                                        disabled={offer.refusal !== null}
                                        onSelect={() => pick(offer)}
                                        className="flex-col items-start gap-0"
                                    >
                                        <span>{offer.label}</span>
                                        <span className="text-muted-foreground text-xs">{offer.refusal ?? offer.description}</span>
                                    </DropdownMenuItem>
                                ))
                            )}
                            <DropdownMenuSeparator />
                        </DropdownMenuGroup>
                    ))}
                    <DropdownMenuItem asChild className="flex-col items-start gap-0">
                        <Link href={`${route('environments.settings', environmentId)}#environment-values`}>
                            <span>Another service</span>
                            <span className="text-muted-foreground text-xs">Add its env values by hand</span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={choice !== null} onOpenChange={(next) => !next && setChoice(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    {choice && <AddResourceForm environmentId={environmentId} offer={choice} onClose={() => setChoice(null)} />}
                </DialogContent>
            </Dialog>
        </>
    );
}

function Backups({
    attachment,
    hasBackupTarget,
    canBackUp,
    canRestore,
    canManageCredentials,
    onRestore,
}: {
    attachment: Attachment;
    hasBackupTarget: boolean;
    canBackUp: boolean;
    canRestore: boolean;
    canManageCredentials: boolean;
    onRestore: (backup: BackupRow) => void;
}) {
    const backups = attachment.backups ?? [];
    const restore = attachment.restore;

    const backUp = () => router.post(route('backups.store', attachment.id), {}, { preserveScroll: true });

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-medium">Backups</h3>
                {canBackUp && (
                    <Button size="sm" variant="secondary" disabled={attachment.status !== 'ready'} onClick={backUp}>
                        Back up now
                    </Button>
                )}
            </div>

            {!hasBackupTarget && (
                <Alert>
                    <AlertDescription>
                        Backups exist only on this server&apos;s disk.{' '}
                        {canManageCredentials ? (
                            <>
                                Add an S3 backup target in{' '}
                                <Link href={route('backup-target.edit')} className="underline">
                                    Settings
                                </Link>
                                .
                            </>
                        ) : (
                            'Ask an owner to add an S3 backup target in Settings.'
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {restore && (
                <p
                    className={cn(
                        'text-sm',
                        restore.status === 'failed' && 'text-red-600 dark:text-red-400',
                        restore.status === 'restored' && 'text-green-700 dark:text-green-400',
                    )}
                >
                    {restore.status === 'running' && `A restore started ${timeAgo(restore.at)} and is still running.`}
                    {restore.status === 'restored' && `The last restore finished ${timeAgo(restore.at)}.`}
                    {restore.status === 'failed' && `The last restore failed ${timeAgo(restore.at)}.${restore.error ? ` ${restore.error}` : ''}`}
                </p>
            )}

            {backups.length === 0 ? (
                <p className="text-muted-foreground text-sm">No backups yet. A backup runs every night.</p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {backups.map((backup) => (
                        <li key={backup.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <time dateTime={backup.created_at}>{new Date(backup.created_at).toLocaleString()}</time>
                            <span className="text-muted-foreground">
                                {backup.size !== null && `${formatBytes(backup.size)}, `}
                                {backup.location === 's3' ? 'S3' : 'this server'}
                            </span>
                            <span className={cn(backup.status === 'failed' && 'text-red-600 dark:text-red-400')}>
                                {backupStatusLabels[backup.status]}
                            </span>
                            {canRestore && backup.status === 'done' && (
                                <Button variant="ghost" size="sm" className="ml-auto" onClick={() => onRestore(backup)}>
                                    Restore
                                </Button>
                            )}
                            {backup.status === 'failed' && backup.error && (
                                <span className="w-full text-red-600 dark:text-red-400">{backup.error}</span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {!canRestore && <p className="text-muted-foreground text-xs">Only an admin or an owner can restore a backup.</p>}
        </section>
    );
}

export function ResourceSheet({
    open,
    onClose,
    attachment,
    environmentId,
    datastores,
    canUpdate,
    canDeploy,
    canRestore,
    canManageCredentials,
}: {
    open: boolean;
    onClose: () => void;
    attachment: Attachment | undefined;
    environmentId: number;
    datastores: Datastores;
    canUpdate: boolean;
    canDeploy: boolean;
    canRestore: boolean;
    canManageCredentials: boolean;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [detaching, setDetaching] = useState(false);
    const [defaulting, setDefaulting] = useState(false);
    const [changingVisibility, setChangingVisibility] = useState(false);
    const [restoring, setRestoring] = useState<BackupRow | null>(null);
    const [busy, setBusy] = useState(false);

    if (!attachment) {
        return null;
    }

    const text = sourceTexts[attachment.source];
    const title = [attachment.label, attachment.server.version].filter(Boolean).join(' ');
    const named = namedTextsFor(attachment);
    const sameFamily = datastores.attachments.filter((each) => each.family === attachment.family);
    const currentDefault = sameFamily.find((each) => each.is_default);
    const keepsTheDefault = named !== null && attachment.is_default && sameFamily.some((each) => each.id !== attachment.id && each.name !== null);
    const canDetach = canUpdate && !keepsTheDefault && attachment.server.status !== 'destroying' && attachment.detach_refusal === null;
    const visibility = attachment.visibility;
    const publishRefusal = visibility && !visibility.public ? visibility.refusal : null;

    const makeDefault = () => {
        router.post(
            route('datastores.default', attachment.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setDefaulting(false);
                },
            },
        );
    };

    const changeVisibility = (makePublic: boolean) => {
        router.put(
            route('datastores.visibility', attachment.id),
            { public: makePublic },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setChangingVisibility(false);
                },
            },
        );
    };

    const detach = () => {
        router.delete(route('datastores.destroy', attachment.id), {
            data: attachment.source === 'managed' ? { confirm: true } : {},
            preserveScroll: true,
            onStart: () => setBusy(true),
            onSuccess: () => onClose(),
            onFinish: () => {
                setBusy(false);
                setDetaching(false);
            },
        });
    };

    const restore = () => {
        if (!restoring) return;

        router.post(
            route('backups.restore', restoring.id),
            { confirm: true },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setRestoring(null);
                },
            },
        );
    };

    return (
        <>
            <EditorSheet
                open={open}
                onClose={onClose}
                title={attachment.name ? `${attachment.name} (${title})` : title}
                description={text.where(attachment)}
                footer={<NextDeployFooter environmentId={environmentId} canDeploy={canDeploy} />}
            >
                {errors.kind && <p className="text-sm text-red-600 dark:text-red-400">{errors.kind}</p>}
                {errors.backup && <p className="text-sm text-red-600 dark:text-red-400">{errors.backup}</p>}
                {errors.confirm && <p className="text-sm text-red-600 dark:text-red-400">{errors.confirm}</p>}

                <dl className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Status</dt>
                    <dd className="flex flex-wrap items-center gap-2">
                        <Status tone={statusTones[attachment.status]}>{statusLabels[attachment.status]}</Status>
                        {attachment.status === 'failed' && canUpdate && attachment.server.status !== 'destroying' && (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() =>
                                    attach(environmentId, attachment.kind, attachment.source, attachment.name, attachment.visibility?.public)
                                }
                            >
                                Try again
                            </Button>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Server</dt>
                    <dd>
                        {attachment.runs_on}, {title}{' '}
                        <span className="text-muted-foreground">({statusLabels[attachment.server.status].toLowerCase()})</span>
                        {attachment.server.status === 'failed' && attachment.server.error && (
                            <span className="block text-red-600 dark:text-red-400">{attachment.server.error}</span>
                        )}
                    </dd>
                </dl>

                {attachment.status === 'failed' && attachment.error && (
                    <p className="text-sm break-words text-red-600 dark:text-red-400">{attachment.error}</p>
                )}

                {named && attachment.name !== null && (
                    <section className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="flex min-w-0 items-center gap-2 text-sm font-medium">
                                {named.heading}
                                <code className="bg-muted truncate rounded px-1.5 py-0.5 font-mono text-xs">{attachment.name}</code>
                                {attachment.is_default && <Badge variant="outline">Default</Badge>}
                            </h3>
                            {canUpdate && !attachment.is_default && (
                                <Button size="sm" variant="secondary" disabled={busy} onClick={() => setDefaulting(true)}>
                                    Make default
                                </Button>
                            )}
                        </div>
                        {attachment.is_default ? (
                            <p className="text-muted-foreground text-sm">{named.isDefault}</p>
                        ) : attachment.config_line ? (
                            <>
                                <p className="text-muted-foreground text-sm">{named.configHint(attachment.name)}</p>
                                <CopyLine text={attachment.config_line} />
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">{named.noConfigLine}</p>
                        )}
                        <InputError message={errors.default} />
                    </section>
                )}

                {visibility && (
                    <section className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="flex min-w-0 items-center gap-2 text-sm font-medium">
                                Visibility
                                <Badge variant="outline">{visibility.public ? 'Public' : 'Private'}</Badge>
                            </h3>
                            {canUpdate && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={busy || publishRefusal !== null}
                                    onClick={() => setChangingVisibility(true)}
                                >
                                    {visibility.public ? 'Make private' : 'Make public'}
                                </Button>
                            )}
                        </div>
                        {visibility.public ? (
                            visibility.url ? (
                                <>
                                    <p className="text-muted-foreground text-sm">
                                        Anyone can read a file at this address. Nobody can list, upload, or delete files without the key.
                                    </p>
                                    <CopyLine text={visibility.url} />
                                    <p className="text-muted-foreground text-xs break-words">
                                        For a file at avatars/a.png, the URL is {visibility.url}/avatars/a.png.
                                    </p>
                                </>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    The bucket has no public address, because the cluster has no public IPv4 address.
                                </p>
                            )
                        ) : (
                            <p className="text-muted-foreground text-sm">{privateHelp}</p>
                        )}
                        {publishRefusal && <p className="text-muted-foreground text-sm">{publishRefusal}</p>}
                        <InputError message={errors.public} />
                    </section>
                )}

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">Env values it sets</h3>
                    {attachment.env_keys.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None yet. The values appear when it is ready.</p>
                    ) : (
                        <div className="flex flex-wrap gap-1">
                            {attachment.env_keys.map((key) => (
                                <code key={key} className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">
                                    {key}
                                </code>
                            ))}
                        </div>
                    )}
                    <p className="text-muted-foreground text-xs">
                        The app reads them on the next deploy.{' '}
                        <Link href={`${route('environments.settings', environmentId)}#environment-values`} className="underline">
                            See all env values
                        </Link>
                    </p>
                </section>

                {attachment.tofu_log && (
                    <details>
                        <summary className="cursor-pointer text-sm font-medium">OpenTofu log</summary>
                        <pre className="bg-muted mt-2 max-h-80 overflow-auto rounded p-3 font-mono text-xs whitespace-pre-wrap">
                            {attachment.tofu_log}
                        </pre>
                    </details>
                )}

                {attachment.backups && (
                    <Backups
                        attachment={attachment}
                        hasBackupTarget={datastores.has_backup_target}
                        canBackUp={canUpdate}
                        canRestore={canRestore}
                        canManageCredentials={canManageCredentials}
                        onRestore={setRestoring}
                    />
                )}

                <section className="space-y-2 border-t pt-5">
                    <h3 className="text-sm font-medium">{text.verb}</h3>
                    <InputError message={errors.detach} />
                    {canDetach ? (
                        <>
                            <p className="text-muted-foreground text-sm">{text.detach(attachment)}</p>
                            <Button variant="destructive" size="sm" onClick={() => setDetaching(true)}>
                                {text.verb} {attachment.label}
                            </Button>
                        </>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {!canUpdate
                                ? 'Only an admin or an owner can change the resources of a protected environment.'
                                : keepsTheDefault
                                  ? named.keepsTheDefault
                                  : (attachment.detach_refusal ?? 'OpenTofu destroys it now.')}
                        </p>
                    )}
                </section>
            </EditorSheet>

            <Dialog open={detaching} onOpenChange={setDetaching}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {text.verb} {attachment.label}?
                        </DialogTitle>
                        <DialogDescription>{text.confirm(attachment)}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDetaching(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={detach}>
                            {text.verb}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={defaulting} onOpenChange={setDefaulting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Make {attachment.name} the default?</DialogTitle>
                        <DialogDescription>
                            On the next deploy, the default env values point to {attachment.name}.
                            {named && currentDefault?.name && ` ${named.oldDefault(currentDefault.name)}`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDefaulting(false)}>
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={makeDefault}>
                            Make default
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {visibility && (
                <Dialog open={changingVisibility} onOpenChange={setChangingVisibility}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Make {attachment.name} {visibility.public ? 'private' : 'public'}?
                            </DialogTitle>
                            <DialogDescription>
                                {visibility.public
                                    ? 'The files stop opening at the public address, so links to them break. On the next deploy, the app loses the URL value.'
                                    : 'Anyone with a URL can read each file in the bucket, also the files that are in it now. Nobody can list, upload, or delete files without the key. On the next deploy, the app gets the URL in its env values.'}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="secondary" onClick={() => setChangingVisibility(false)}>
                                Cancel
                            </Button>
                            <Button disabled={busy} onClick={() => changeVisibility(!visibility.public)}>
                                {visibility.public ? 'Make private' : 'Make public'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

            <Dialog open={restoring !== null} onOpenChange={(next) => !next && setRestoring(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Restore the backup from {restoring && new Date(restoring.created_at).toLocaleString()}?</DialogTitle>
                        <DialogDescription>
                            The current data is replaced by this backup. A backup of the current state runs first, so you can go back.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setRestoring(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={restore}>
                            Replace the current data
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
