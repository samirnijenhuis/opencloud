import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';
import { Status, type Tone } from './block';
import { EditorSheet, NextDeployFooter } from './editor-sheet';
import { type Attachment, type BackupRow, type DatastoreKind, type DatastoreStatus, type Datastores, kindLabels, sqlKinds } from './types';

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

const kindPurposes: Record<DatastoreKind, string> = {
    mysql: 'A SQL database in this cluster',
    postgres: 'A SQL database in this cluster',
    valkey: 'Cache, queues, and sessions',
    rustfs: 'S3 storage for files',
};

// DigitalOcean MySQL needs a TLS CA that apps do not load yet, so only Postgres is offered as managed.
const offeredManagedKinds: DatastoreKind[] = ['postgres'];

const backupStatusLabels = { running: 'Running', done: 'Done', failed: 'Failed' } as const;

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;

    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

type Source = 'standard' | 'managed';

type DatabaseChoice = { kind: DatastoreKind; source: Source };

const attach = (environmentId: number, kind: DatastoreKind, source: Source = 'standard', name: string | null = null) => {
    router.post(route('datastores.store', environmentId), { kind, source, name }, { preserveScroll: true });
};

/** Only to show the config line while the user types. The server checks the name and gives the messages. */
const namePattern = /^[a-z][a-z0-9_]{0,29}$/;

const laravelDrivers: Partial<Record<DatastoreKind, string>> = { mysql: 'mysql', postgres: 'pgsql' };

const urlKey = (name: string) => `${name.toUpperCase()}_DB_URL`;

const configLine = (kind: DatastoreKind, name: string) => `'${name}' => ['driver' => '${laravelDrivers[kind]}', 'url' => env('${urlKey(name)}')],`;

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

/** The kinds a user can attach. A MySQL or Postgres database first asks for its connection name. */
export function AddResourceMenu({ environmentId, datastores, children }: { environmentId: number; datastores: Datastores; children: ReactNode }) {
    const { available_kinds, managed } = datastores;
    const [choice, setChoice] = useState<DatabaseChoice | null>(null);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>{children}</DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-72">
                    {available_kinds.map((kind) => (
                        <DropdownMenuItem
                            key={kind}
                            onSelect={() => (sqlKinds.includes(kind) ? setChoice({ kind, source: 'standard' }) : attach(environmentId, kind))}
                            className="flex-col items-start gap-0"
                        >
                            <span>{kindLabels[kind]}</span>
                            <span className="text-muted-foreground text-xs">{kindPurposes[kind]}</span>
                        </DropdownMenuItem>
                    ))}
                    {managed.available &&
                        offeredManagedKinds.map((kind) => (
                            <DropdownMenuItem
                                key={`managed-${kind}`}
                                onSelect={() => setChoice({ kind, source: 'managed' })}
                                className="flex-col items-start gap-0"
                            >
                                <span>Managed {kindLabels[kind]}</span>
                                <span className="text-muted-foreground text-xs">
                                    DigitalOcean runs it, backs it up, and charges for it. Size {managed.size}.
                                </span>
                            </DropdownMenuItem>
                        ))}
                    {available_kinds.length > 0 && <DropdownMenuSeparator />}
                    <DropdownMenuItem asChild className="flex-col items-start gap-0">
                        <Link href={`${route('environments.settings', environmentId)}#environment-values`}>
                            <span>External service</span>
                            <span className="text-muted-foreground text-xs">Add env values for a service you run elsewhere</span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={choice !== null} onOpenChange={(next) => !next && setChoice(null)}>
                <DialogContent>
                    {choice && (
                        <AddDatabaseForm environmentId={environmentId} datastores={datastores} choice={choice} onClose={() => setChoice(null)} />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

/** The dialog content mounts on each open, so the name starts fresh: "main" for the first database, empty after. */
function AddDatabaseForm({
    environmentId,
    datastores,
    choice,
    onClose,
}: {
    environmentId: number;
    datastores: Datastores;
    choice: DatabaseChoice;
    onClose: () => void;
}) {
    const first = !datastores.attachments.some((attachment) => attachment.name !== null);
    const [name, setName] = useState(first ? 'main' : '');
    const [busy, setBusy] = useState(false);
    const [refusal, setRefusal] = useState<string | undefined>();
    const label = kindLabels[choice.kind];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.post(
            route('datastores.store', environmentId),
            { kind: choice.kind, source: choice.source, name },
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setRefusal(undefined);
                },
                onSuccess: onClose,
                // The page reloads its datastores while one is pending, and a reload clears the page errors.
                onError: (errors) => setRefusal(errors.kind),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid min-w-0 gap-4">
            <DialogHeader>
                <DialogTitle>Add {choice.source === 'managed' ? `managed ${label}` : label}</DialogTitle>
                <DialogDescription>
                    {first
                        ? 'This is the first database of the environment, so it is the default. Laravel reads its DB_* values with no config.'
                        : 'The DB_* values stay with the default database. The app reaches this database through one env value.'}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2">
                <Label htmlFor="database-name">Connection name</Label>
                <Input
                    id="database-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="reports"
                    autoComplete="off"
                    spellCheck={false}
                    autoFocus
                    className="font-mono"
                />
                <p className="text-muted-foreground text-xs">Use 1 to 30 lowercase letters, digits, and underscores. Start with a letter.</p>
                <InputError message={refusal} />
            </div>

            {!first && namePattern.test(name) && (
                <div className="grid min-w-0 gap-2 text-sm">
                    <p>
                        Opencloud sets <code className="bg-muted rounded px-1 font-mono text-xs">{urlKey(name)}</code>. Add this line to the
                        connections in config/database.php.
                    </p>
                    <CopyLine text={configLine(choice.kind, name)} />
                    <p className="text-muted-foreground text-xs">Then use DB::connection(&apos;{name}&apos;) in the app.</p>
                </div>
            )}

            {choice.source === 'managed' && (
                <p className="text-muted-foreground text-sm">
                    DigitalOcean runs it, backs it up, and charges for it. Size {datastores.managed.size}.
                </p>
            )}

            <DialogFooter className="gap-2">
                <Button type="button" variant="secondary" onClick={onClose}>
                    Cancel
                </Button>
                <Button disabled={busy || name.trim() === ''}>Add {label}</Button>
            </DialogFooter>
        </form>
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
    const [restoring, setRestoring] = useState<BackupRow | null>(null);
    const [busy, setBusy] = useState(false);

    if (!attachment) {
        return null;
    }

    const label = kindLabels[attachment.kind];
    const currentDefault = datastores.attachments.find((each) => each.is_default);
    const keepsTheDefault = attachment.is_default && datastores.attachments.some((each) => each.id !== attachment.id && each.name !== null);
    const canDetach =
        canUpdate && !keepsTheDefault && attachment.server.status !== 'destroying' && (!attachment.managed || datastores.managed.available);

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

    const detach = () => {
        router.delete(route('datastores.destroy', attachment.id), {
            data: attachment.managed ? { confirm: true } : {},
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
                title={attachment.name ? `${attachment.name} (${label} ${attachment.server.version})` : `${label} ${attachment.server.version}`}
                description={attachment.managed ? 'A managed database on DigitalOcean.' : `Runs in the cluster ${attachment.server.cluster}.`}
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
                            <Button variant="secondary" size="sm" onClick={() => attach(environmentId, attachment.kind, 'standard', attachment.name)}>
                                Try again
                            </Button>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Server</dt>
                    <dd>
                        {attachment.managed ? 'DigitalOcean' : attachment.server.cluster}, {label} {attachment.server.version}{' '}
                        <span className="text-muted-foreground">({statusLabels[attachment.server.status].toLowerCase()})</span>
                        {attachment.server.status === 'failed' && attachment.server.error && (
                            <span className="block text-red-600 dark:text-red-400">{attachment.server.error}</span>
                        )}
                    </dd>
                </dl>

                {attachment.status === 'failed' && attachment.error && (
                    <p className="text-sm break-words text-red-600 dark:text-red-400">{attachment.error}</p>
                )}

                {attachment.name !== null && (
                    <section className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="flex min-w-0 items-center gap-2 text-sm font-medium">
                                Connection
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
                            <p className="text-muted-foreground text-sm">
                                This is the default database. The DB_* values point to it, so Laravel uses it with no config.
                            </p>
                        ) : (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Add this line to the connections in config/database.php. Then use DB::connection(&apos;{attachment.name}&apos;) in
                                    the app.
                                </p>
                                {attachment.config_line && <CopyLine text={attachment.config_line} />}
                            </>
                        )}
                        <InputError message={errors.default} />
                    </section>
                )}

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">Env values it sets</h3>
                    {attachment.env_keys.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None yet. The values appear when the database is ready.</p>
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
                    <h3 className="text-sm font-medium">{attachment.managed ? 'Destroy' : 'Detach'}</h3>
                    <InputError message={errors.detach} />
                    {canDetach ? (
                        <>
                            <p className="text-muted-foreground text-sm">
                                {attachment.managed
                                    ? 'OpenTofu destroys the database on DigitalOcean with its data and backups.'
                                    : 'The app loses access. The data stays on the server.'}
                            </p>
                            <Button variant="destructive" size="sm" onClick={() => setDetaching(true)}>
                                {attachment.managed ? 'Destroy the database' : `Detach ${label}`}
                            </Button>
                        </>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {!canUpdate
                                ? 'Only an admin or an owner can change the resources of a protected environment.'
                                : keepsTheDefault
                                  ? 'This is the default database. Make another database the default first.'
                                  : 'Only an owner can destroy a managed database.'}
                        </p>
                    )}
                </section>
            </EditorSheet>

            <Dialog open={detaching} onOpenChange={setDetaching}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {attachment.managed ? 'Destroy' : 'Detach'} {label}?
                        </DialogTitle>
                        <DialogDescription>
                            {attachment.managed
                                ? 'OpenTofu destroys the managed database on DigitalOcean. DigitalOcean deletes the data and its backups. You cannot undo this. The env values it set are removed now.'
                                : attachment.name
                                  ? 'The app loses its access and the env values it set are removed. The data stays on the server. Attach a database with the same kind and name again to use it.'
                                  : 'The app loses its access and the env values it set are removed. The data stays on the server. Attach the same kind again to use it.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDetaching(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={detach}>
                            {attachment.managed ? 'Destroy the database' : 'Detach'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={defaulting} onOpenChange={setDefaulting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Make {attachment.name} the default?</DialogTitle>
                        <DialogDescription>
                            On the next deploy, the DB_* values point to {attachment.name}.
                            {currentDefault?.name &&
                                ` ${currentDefault.name} gets the value ${urlKey(currentDefault.name)}, and the app needs a line in config/database.php to use it.`}
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
