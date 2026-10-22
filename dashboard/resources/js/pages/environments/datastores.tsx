import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import EnvironmentLayout from '@/layouts/environment-layout';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type EnvironmentPageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Kind = 'mysql' | 'postgres' | 'valkey' | 'rustfs';
type Status = 'pending' | 'provisioning' | 'ready' | 'failed';

type BackupRow = {
    id: number;
    status: 'running' | 'done' | 'failed';
    size: number | null;
    location: 'local' | 's3';
    error: string | null;
    created_at: string;
};

type Restore = { status: 'running' | 'restored' | 'failed'; at: string; error: string | null };

type Attachment = {
    id: number;
    kind: Kind;
    status: Status;
    error: string | null;
    server: { cluster: string; version: string; status: Status; error: string | null };
    env_keys: string[];
    backups?: BackupRow[];
    restore?: Restore | null;
};

type Props = EnvironmentPageProps & {
    can: EnvironmentPageProps['can'] & { restore: boolean; manage_backup_target: boolean };
    attachments: Attachment[];
    available_kinds: Kind[];
    has_backup_target: boolean;
    polling: boolean;
};

const kindLabels: Record<Kind, string> = { mysql: 'MySQL', postgres: 'Postgres', valkey: 'Valkey', rustfs: 'RustFS' };

const statusStyles: Record<Status, string> = {
    pending: 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    provisioning: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    ready: 'border-transparent bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};

const statusLabels: Record<Status, string> = { pending: 'Pending', provisioning: 'Provisioning', ready: 'Ready', failed: 'Failed' };

const backupStatusLabels = { running: 'Running', done: 'Done', failed: 'Failed' } as const;

function formatBytes(bytes: number | null): string {
    if (bytes === null) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;

    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

export default function EnvironmentDatastores(props: Props) {
    const { environment, attachments, available_kinds, has_backup_target, polling, can } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const [busy, setBusy] = useState(false);
    const [detaching, setDetaching] = useState<Attachment | null>(null);
    const [restoring, setRestoring] = useState<BackupRow | null>(null);

    useEffect(() => {
        if (!polling) return;

        const timer = setInterval(() => router.reload({ only: ['attachments', 'available_kinds', 'polling', 'has_backup_target'] }), 3000);

        return () => clearInterval(timer);
    }, [polling]);

    const attach = (kind: Kind) => {
        router.post(
            route('datastores.store', environment.id),
            { kind },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    const detach = () => {
        if (!detaching) return;

        router.delete(route('datastores.destroy', detaching.id), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setDetaching(null);
            },
        });
    };

    const backUp = (attachment: Attachment) => {
        router.post(
            route('backups.store', attachment.id),
            {},
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
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
        <EnvironmentLayout page={props} tab="datastores" title="Datastores">
            {!has_backup_target && (
                <Alert>
                    <AlertDescription>
                        Backups exist only on this server&apos;s disk.{' '}
                        {can.manage_backup_target ? (
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

            {errors.kind && <p className="text-sm text-red-600 dark:text-red-400">{errors.kind}</p>}
            {errors.backup && <p className="text-sm text-red-600 dark:text-red-400">{errors.backup}</p>}
            {errors.confirm && <p className="text-sm text-red-600 dark:text-red-400">{errors.confirm}</p>}

            {attachments.length === 0 ? (
                <p className="text-sm">This environment has no datastores yet.</p>
            ) : (
                <ul className="space-y-4">
                    {attachments.map((attachment) => (
                        <li key={attachment.id} className="space-y-3 rounded-lg border p-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="font-medium">{kindLabels[attachment.kind]}</h2>
                                <Badge className={cn(statusStyles[attachment.status])}>{statusLabels[attachment.status]}</Badge>
                                <span className="ml-auto flex gap-1">
                                    {attachment.status === 'failed' && can.update && (
                                        <Button variant="secondary" size="sm" disabled={busy} onClick={() => attach(attachment.kind)}>
                                            Try again
                                        </Button>
                                    )}
                                    {can.update && (
                                        <Button variant="ghost" size="sm" onClick={() => setDetaching(attachment)}>
                                            Detach
                                        </Button>
                                    )}
                                </span>
                            </div>

                            {attachment.status === 'failed' && attachment.error && (
                                <p className="text-sm break-words text-red-600 dark:text-red-400">{attachment.error}</p>
                            )}

                            <dl className="grid gap-2 text-sm sm:grid-cols-[8rem_1fr]">
                                <dt className="text-muted-foreground">Server</dt>
                                <dd>
                                    {attachment.server.cluster}, {kindLabels[attachment.kind]} {attachment.server.version}{' '}
                                    <span className="text-muted-foreground">({statusLabels[attachment.server.status].toLowerCase()})</span>
                                    {attachment.server.status === 'failed' && attachment.server.error && (
                                        <span className="block text-red-600 dark:text-red-400">{attachment.server.error}</span>
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">Sets these values</dt>
                                <dd className="flex flex-wrap gap-1">
                                    {attachment.env_keys.length === 0 ? (
                                        <span className="text-muted-foreground">None yet</span>
                                    ) : (
                                        attachment.env_keys.map((key) => (
                                            <code key={key} className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">
                                                {key}
                                            </code>
                                        ))
                                    )}
                                </dd>
                            </dl>

                            {attachment.backups && (
                                <BackupsSection
                                    attachment={attachment}
                                    busy={busy}
                                    canBackUp={can.update}
                                    canRestore={can.restore}
                                    onBackUp={() => backUp(attachment)}
                                    onRestore={setRestoring}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can.update ? (
                available_kinds.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {available_kinds.map((kind) => (
                            <Button key={kind} variant="secondary" disabled={busy} onClick={() => attach(kind)}>
                                Attach {kindLabels[kind]}
                            </Button>
                        ))}
                    </div>
                )
            ) : (
                <p className="text-muted-foreground text-sm">Only an admin or an owner can change the datastores of a protected environment.</p>
            )}

            <Dialog open={detaching !== null} onOpenChange={(open) => !open && setDetaching(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Detach {detaching && kindLabels[detaching.kind]}?</DialogTitle>
                        <DialogDescription>
                            The application loses its access and the values listed above are removed. The data stays on the server. Attach the same
                            kind again to use it.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDetaching(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={detach}>
                            Detach
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={restoring !== null} onOpenChange={(open) => !open && setRestoring(null)}>
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
        </EnvironmentLayout>
    );
}

function BackupsSection({
    attachment,
    busy,
    canBackUp,
    canRestore,
    onBackUp,
    onRestore,
}: {
    attachment: Attachment;
    busy: boolean;
    canBackUp: boolean;
    canRestore: boolean;
    onBackUp: () => void;
    onRestore: (backup: BackupRow) => void;
}) {
    const backups = attachment.backups ?? [];
    const restore = attachment.restore;

    return (
        <div className="space-y-3 border-t pt-3">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-medium">Backups</h3>
                {canBackUp && (
                    <Button size="sm" variant="secondary" disabled={busy || attachment.status !== 'ready'} onClick={onBackUp}>
                        Back up now
                    </Button>
                )}
            </div>

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
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground border-b text-left">
                            <tr>
                                <th className="py-2 pr-3 font-medium">Time</th>
                                <th className="py-2 pr-3 font-medium">Size</th>
                                <th className="py-2 pr-3 font-medium">Location</th>
                                <th className="py-2 pr-3 font-medium">Status</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {backups.map((backup) => (
                                <tr key={backup.id}>
                                    <td className="py-2 pr-3">
                                        <time dateTime={backup.created_at}>{new Date(backup.created_at).toLocaleString()}</time>
                                    </td>
                                    <td className="py-2 pr-3">{formatBytes(backup.size)}</td>
                                    <td className="py-2 pr-3">{backup.location === 's3' ? 'S3' : 'This server'}</td>
                                    <td className="py-2 pr-3">
                                        {backupStatusLabels[backup.status]}
                                        {backup.status === 'failed' && backup.error && (
                                            <span className="block text-red-600 dark:text-red-400">{backup.error}</span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        {canRestore && backup.status === 'done' && (
                                            <Button variant="ghost" size="sm" onClick={() => onRestore(backup)}>
                                                Restore
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {!canRestore && <p className="text-muted-foreground text-xs">Only an admin or an owner can restore a backup.</p>}
        </div>
    );
}
