import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
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

const attach = (environmentId: number, kind: DatastoreKind, source: 'standard' | 'managed' = 'standard') => {
    router.post(route('datastores.store', environmentId), { kind, source }, { preserveScroll: true });
};

/** The kinds a user can attach. A second SQL database shows, but disabled, so the user learns the rule. */
export function AddResourceMenu({ environmentId, datastores, children }: { environmentId: number; datastores: Datastores; children: ReactNode }) {
    const { attachments, available_kinds, managed } = datastores;
    const sql = attachments.find((attachment) => sqlKinds.includes(attachment.kind));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{children}</DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-72">
                {available_kinds.map((kind) => {
                    const blocked = sql !== undefined && sqlKinds.includes(kind);

                    return (
                        <DropdownMenuItem
                            key={kind}
                            disabled={blocked}
                            onSelect={() => attach(environmentId, kind)}
                            className="flex-col items-start gap-0"
                        >
                            <span>{kindLabels[kind]}</span>
                            <span className="text-muted-foreground text-xs">
                                {blocked ? `An environment has one SQL database, and ${kindLabels[sql.kind]} is attached.` : kindPurposes[kind]}
                            </span>
                        </DropdownMenuItem>
                    );
                })}
                {managed.available &&
                    sql === undefined &&
                    offeredManagedKinds.map((kind) => (
                        <DropdownMenuItem
                            key={`managed-${kind}`}
                            onSelect={() => attach(environmentId, kind, 'managed')}
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
    const [restoring, setRestoring] = useState<BackupRow | null>(null);
    const [busy, setBusy] = useState(false);

    if (!attachment) {
        return null;
    }

    const label = kindLabels[attachment.kind];
    const canDetach = canUpdate && attachment.server.status !== 'destroying' && (!attachment.managed || datastores.managed.available);

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
                title={`${label} ${attachment.server.version}`}
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
                            <Button variant="secondary" size="sm" onClick={() => attach(environmentId, attachment.kind)}>
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
                            {canUpdate
                                ? 'Only an owner can destroy a managed database.'
                                : 'Only an admin or an owner can change the resources of a protected environment.'}
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
