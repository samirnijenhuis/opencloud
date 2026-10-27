import { AppSheet } from '@/components/environment/app-sheet';
import { AddButton, Block, Group, Row, Status, SubHeading, type Tone } from '@/components/environment/block';
import { DomainsSheet } from '@/components/environment/domains-sheet';
import { EditorSheet } from '@/components/environment/editor-sheet';
import { AddResourceMenu, namedTextsFor, ResourceSheet, statusLabels, statusTones } from '@/components/environment/resource-sheet';
import {
    type Attachment,
    type CanvasProps,
    type Capacity,
    type Domain,
    type LiveStatus,
    type Resources,
    type Sheet,
} from '@/components/environment/types';
import { DeploymentBadge, isRunning } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePoll } from '@/hooks/use-poll';
import EnvironmentLayout from '@/layouts/environment-layout';
import { duration, memoryText, shortSha, sizeLabel, sizeText, timeAgo, vcpu } from '@/lib/format';
import { kindLabels as clusterKindLabels } from '@/pages/clusters/cluster-status';
import { Link, router } from '@inertiajs/react';
import { Boxes, Database, Globe, HardDrive, Plus, Server, ShieldCheck, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';

const resourceIcons = { mysql: Database, postgres: Database, valkey: Zap, rustfs: HardDrive } as const;

const issuerLabels = { acme: "Let's Encrypt", internal: 'Local CA' } as const;

const live = (domain: Domain) => domain.dns_status === 'ok' || domain.dns_status === 'proxied';

/** The one short state of a domain that fits in a block row. */
function domainState(domain: Domain): [Tone, string] {
    if (domain.dns_status === 'wrong') return ['bad', 'Wrong address'];
    if (domain.dns_status === 'missing') return ['warn', 'Add A record'];
    if (!live(domain)) return ['off', 'Checking'];
    if (domain.certificate_status === 'failed') return ['bad', 'Certificate failed'];
    if (domain.certificate_status !== 'issued') return ['warn', 'Issuing'];

    return ['ok', 'Live'];
}

function certificatesState(domains: Domain[]): [Tone, string] {
    const failed = domains.filter((domain) => domain.certificate_status === 'failed').length;
    const waiting = domains.filter((domain) => domain.certificate_status !== 'issued').length;

    if (domains.length === 0) return ['off', 'No domains'];
    if (failed > 0) return ['bad', `${failed} failed`];
    if (waiting > 0) return ['warn', `${waiting} pending`];

    return ['ok', 'Issued'];
}

function readyState(status: LiveStatus | null, name: string): [Tone, string] {
    const process = status?.processes.find((each) => each.name === name);

    if (!process) return ['off', '-'];
    if (process.desired === 0) return ['off', '0/0'];

    return [process.ready === process.desired ? 'ok' : 'warn', `${process.ready}/${process.desired}`];
}

function HttpsSheet({ open, onClose, props }: { open: boolean; onClose: () => void; props: CanvasProps }) {
    const { domains, tls_issuer, cloudflare_zones, can } = props;

    return (
        <EditorSheet open={open} onClose={onClose} title="HTTPS" description="Caddy on the cluster ends TLS for every domain of this environment.">
            <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-3 text-sm">
                <dt className="text-muted-foreground">Certificates</dt>
                <dd>Caddy gets a certificate for each domain and renews it by itself.</dd>
                <dt className="text-muted-foreground">Issuer</dt>
                <dd>
                    {issuerLabels[tls_issuer]}
                    {tls_issuer === 'internal' && (
                        <span className="text-muted-foreground block">Browsers do not trust the local CA. Use it only for tests.</span>
                    )}
                </dd>
                <dt className="text-muted-foreground">HTTP</dt>
                <dd>Caddy sends each HTTP request to HTTPS.</dd>
                <dt className="text-muted-foreground">Cloudflare DNS</dt>
                <dd>
                    {cloudflare_zones.length > 0
                        ? `The Cloudflare key reaches ${cloudflare_zones.join(', ')}. For a domain in these zones, opencloud can manage the DNS record.`
                        : 'No Cloudflare key is set. You add the DNS records yourself.'}
                </dd>
            </dl>

            {domains.length > 0 && (
                <ul className="divide-y rounded-lg border text-sm">
                    {domains.map((domain) => (
                        <li key={domain.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2">
                            <span className="min-w-0 font-mono break-all">{domain.hostname}</span>
                            <span className="text-muted-foreground flex gap-3">
                                {domain.dns_mode === 'cloudflare' && <span>Cloudflare DNS</span>}
                                <Status
                                    tone={domain.certificate_status === 'issued' ? 'ok' : domain.certificate_status === 'failed' ? 'bad' : 'warn'}
                                >
                                    {domain.certificate_status === 'issued'
                                        ? 'Issued'
                                        : domain.certificate_status === 'failed'
                                          ? 'Failed'
                                          : 'Pending'}
                                </Status>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-muted-foreground text-sm">
                {can.manage_credentials ? (
                    <>
                        Set the Cloudflare key in{' '}
                        <Link href={route('provider-keys.index')} className="text-foreground underline">
                            Settings, Providers
                        </Link>
                        .
                    </>
                ) : (
                    'Only an owner can set the Cloudflare key.'
                )}
            </p>
        </EditorSheet>
    );
}

function Meter({ label, used, mine, total, text }: { label: string; used: number; mine: number; total: number; text: string }) {
    const percent = (value: number) => `${Math.min(100, (value / total) * 100)}%`;

    return (
        <span className="flex items-center gap-2">
            <span>{label}</span>
            <span className="bg-muted relative flex h-1.5 w-28 overflow-hidden rounded-full border-0" role="img" aria-label={`${label}: ${text}`}>
                <span className="bg-foreground h-full" style={{ width: percent(mine) }} />
                <span className="bg-foreground/30 h-full" style={{ width: percent(Math.max(0, used - mine)) }} />
            </span>
            <span className="text-foreground tabular-nums">{text}</span>
        </span>
    );
}

function CapacityBar({ clusterName, capacity, requests }: { clusterName: string; capacity: Capacity | null; requests: Resources | null }) {
    if (capacity === null) {
        return <p className="text-muted-foreground text-sm">Reading the capacity of {clusterName}.</p>;
    }

    if (capacity.state !== 'ok' || !capacity.allocatable || !capacity.requested) {
        return (
            <p className="text-muted-foreground text-sm">
                The capacity of {clusterName} is unknown right now. The dashboard cannot read it from the cluster.
            </p>
        );
    }

    const { allocatable, requested } = capacity;

    return (
        <div className="text-muted-foreground flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span>
                Capacity of <span className="text-foreground">{clusterName}</span>
            </span>
            <Meter
                label="CPU"
                used={requested.cpu_millicores}
                mine={requests?.cpu_millicores ?? 0}
                total={allocatable.cpu_millicores}
                text={`${vcpu(requested.cpu_millicores)} of ${vcpu(allocatable.cpu_millicores)} vCPU`}
            />
            <Meter
                label="Memory"
                used={requested.memory_mib}
                mine={requests?.memory_mib ?? 0}
                total={allocatable.memory_mib}
                text={`${memoryText(requested.memory_mib)} of ${memoryText(allocatable.memory_mib)}`}
            />
            <span className="flex items-center gap-1.5 text-xs">
                <span aria-hidden className="bg-foreground size-2 rounded-sm" /> This environment
                <span aria-hidden className="bg-foreground/30 ml-2 size-2 rounded-sm" /> Other workloads
            </span>
        </div>
    );
}

function ResourceCard({ attachment, onOpen }: { attachment: Attachment; onOpen: () => void }) {
    const lastBackup = attachment.backups?.find((backup) => backup.status === 'done');
    const named = namedTextsFor(attachment);

    return (
        <Block
            icon={resourceIcons[attachment.kind]}
            title={[attachment.label, attachment.server.version].filter(Boolean).join(' ')}
            aside={<Status tone={statusTones[attachment.status]}>{statusLabels[attachment.status]}</Status>}
            onOpen={onOpen}
        >
            {named && attachment.name && (
                <Row label={named.heading}>
                    <span className="inline-flex max-w-full items-center gap-1.5">
                        <code className="truncate font-mono text-xs">{attachment.name}</code>
                        {attachment.is_default && (
                            <Badge variant="outline" className="px-1.5 py-0 font-medium">
                                Default
                            </Badge>
                        )}
                    </span>
                </Row>
            )}
            <Row label="Runs on">{attachment.runs_on}</Row>
            <Row label="Env values">{attachment.env_keys.length}</Row>
            {attachment.backups && <Row label="Last backup">{lastBackup ? timeAgo(lastBackup.created_at) : 'None yet'}</Row>}
        </Block>
    );
}

export default function EnvironmentShow(props: CanvasProps) {
    const { environment, cluster, compute, sizes, processes, domains, datastores, deployments, can } = props;
    const [sheet, setSheet] = useState<Sheet>({ kind: 'app', tab: 'general' });
    const [open, setOpen] = useState(false);
    const hasPods = environment.kind !== 'preview_template';
    const [status] = usePoll<LiveStatus>(hasPods ? route('environments.status', environment.id) : null, 10_000);
    const [capacity] = usePoll<Capacity>(route('clusters.capacity', cluster.id), 30_000);

    useEffect(() => {
        if (!datastores.polling) return;

        const timer = setInterval(() => router.reload({ only: ['datastores'] }), 3000);

        return () => clearInterval(timer);
    }, [datastores.polling]);

    const show = (next: Sheet) => {
        setSheet(next);
        setOpen(true);
    };
    const close = () => setOpen(false);

    const [certTone, certText] = certificatesState(domains);
    const cloudflareCount = domains.filter((domain) => domain.dns_mode === 'cloudflare').length;
    const autoscales = compute.max_instances > compute.min_instances;
    const size = sizes.find((option) => option.value === compute.size);
    const statusUnknown = hasPods && status?.state === 'unknown';
    const notRunning = status?.state === 'ok' && processes.some((process) => !status.processes.some((each) => each.name === process.name));
    const addResource = can.update && (
        <AddResourceMenu environmentId={environment.id} datastores={datastores}>
            <Button variant="ghost" size="sm">
                <Plus /> Resource
            </Button>
        </AddResourceMenu>
    );

    return (
        <EnvironmentLayout page={props} tab="environment" title="Environment">
            <div className="@container relative rounded-xl border bg-neutral-50/70 bg-[radial-gradient(var(--color-neutral-300)_1px,transparent_1px)] bg-[size:14px_14px] p-4 pb-20 sm:p-6 sm:pb-20 dark:bg-neutral-950 dark:bg-[radial-gradient(var(--color-neutral-800)_1px,transparent_1px)]">
                <div className="mx-auto grid max-w-md grid-cols-1 gap-6 @4xl:max-w-none @4xl:grid-cols-[minmax(0,1fr)_1.75rem_minmax(0,1.1fr)_1.75rem_minmax(0,1fr)] @4xl:items-start @4xl:gap-0">
                    <Group label="Network" aside="Caddy">
                        <Block icon={ShieldCheck} title="HTTPS" onOpen={() => show({ kind: 'https' })}>
                            <Row label="Certificates">
                                <Status tone={certTone}>{certText}</Status>
                            </Row>
                            <Row label="HTTP to HTTPS">
                                <Status tone="ok">On</Status>
                            </Row>
                            <Row label="Issuer">{issuerLabels[props.tls_issuer]}</Row>
                            <Row label="Cloudflare DNS">
                                {cloudflareCount > 0 ? `${cloudflareCount} of ${domains.length} domains` : <Status tone="off">Off</Status>}
                            </Row>
                        </Block>
                        <Block icon={Globe} title="Domains" onOpen={() => show({ kind: 'domains', adding: false })}>
                            {domains.length === 0 && <p className="text-muted-foreground px-3 py-1.5 text-sm">No domain yet.</p>}
                            {domains.map((domain) => {
                                const [tone, text] = domainState(domain);

                                return (
                                    <Row
                                        key={domain.id}
                                        label={
                                            <span className="text-foreground" title={domain.hostname}>
                                                {domain.hostname}
                                            </span>
                                        }
                                    >
                                        <span className="flex items-center gap-2">
                                            {domain.generated && <span className="text-muted-foreground text-xs">Free</span>}
                                            <Status tone={tone}>{text}</Status>
                                        </span>
                                    </Row>
                                );
                            })}
                            {can.manage_domains && <AddButton onClick={() => show({ kind: 'domains', adding: true })}>Add domain</AddButton>}
                        </Block>
                    </Group>

                    <Connector />

                    <Group label={cluster.name} aside={clusterKindLabels[cluster.kind]}>
                        <Block icon={Server} title="App" onOpen={() => show({ kind: 'app', tab: 'general' })}>
                            <Row label="Size">{size ? `${sizeLabel(size.value)}, ${sizeText(size)}` : sizeLabel(compute.size)}</Row>
                            <Row label="Instances">
                                {autoscales
                                    ? `${compute.min_instances} to ${compute.max_instances} at ${compute.cpu_target}% CPU`
                                    : compute.min_instances}
                            </Row>
                            <Row label="Health check">
                                <code className="font-mono text-xs">{compute.health_path}</code>
                            </Row>
                            <SubHeading>
                                <span className="grid grid-cols-[minmax(0,1fr)_3rem_3rem_4rem] gap-2">
                                    <span>Process</span>
                                    <span className="text-right">Ready</span>
                                    <span className="text-right">vCPU</span>
                                    <span className="text-right">Memory</span>
                                </span>
                            </SubHeading>
                            {['web', ...processes.map((process) => process.name)].map((name) => {
                                const [tone, ready] = readyState(status, name);
                                const metrics = status?.processes.find((each) => each.name === name);

                                return (
                                    <div
                                        key={name}
                                        className="grid grid-cols-[minmax(0,1fr)_3rem_3rem_4rem] items-center gap-2 px-3 py-1.5 text-sm tabular-nums"
                                    >
                                        <span className="truncate font-mono text-xs">{name}</span>
                                        <span className="text-right">{tone === 'off' ? ready : <Status tone={tone}>{ready}</Status>}</span>
                                        <span className="text-muted-foreground text-right">
                                            {metrics?.cpu_millicores != null ? vcpu(metrics.cpu_millicores) : '-'}
                                        </span>
                                        <span className="text-muted-foreground text-right">
                                            {metrics?.memory_mib != null ? memoryText(metrics.memory_mib) : '-'}
                                        </span>
                                    </div>
                                );
                            })}
                            {statusUnknown && (
                                <p className="text-muted-foreground px-3 py-1.5 text-xs">
                                    The live status is unknown right now. The dashboard cannot read it from the cluster.
                                </p>
                            )}
                            {notRunning && <p className="text-muted-foreground px-3 py-1.5 text-xs">A new process starts on the next deploy.</p>}
                            {can.update && <AddButton onClick={() => show({ kind: 'app', tab: 'processes' })}>Add process</AddButton>}
                        </Block>
                    </Group>

                    <Connector />

                    <Group label="Resources" className="border-transparent bg-transparent">
                        {datastores.attachments.map((attachment) => (
                            <ResourceCard key={attachment.id} attachment={attachment} onOpen={() => show({ kind: 'resource', id: attachment.id })} />
                        ))}
                        {datastores.attachments.length === 0 && (
                            <p className="text-muted-foreground px-1.5 text-sm">No database, cache, or storage yet.</p>
                        )}
                        {can.update && (
                            <AddResourceMenu environmentId={environment.id} datastores={datastores}>
                                <button
                                    type="button"
                                    className="bg-card/60 hover:bg-card text-muted-foreground hover:text-foreground focus-visible:ring-ring flex w-full items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-left text-sm outline-none focus-visible:ring-2"
                                >
                                    <Boxes className="size-4" />
                                    Add resource
                                    <span className="ml-auto text-xs">Database, cache, storage</span>
                                </button>
                            </AddResourceMenu>
                        )}
                    </Group>
                </div>

                {!can.manage_domains && !can.update && (
                    <p className="text-muted-foreground absolute inset-x-4 bottom-6 text-center text-xs">
                        Only an admin or an owner can change a protected environment. You can open each block to read it.
                    </p>
                )}
                {(can.manage_domains || can.update) && (
                    <div className="bg-background absolute bottom-4 left-1/2 flex -translate-x-1/2 gap-1 rounded-lg border p-1 shadow-sm">
                        {can.manage_domains && (
                            <Button variant="ghost" size="sm" onClick={() => show({ kind: 'domains', adding: true })}>
                                <Plus /> Domain
                            </Button>
                        )}
                        {can.update && (
                            <Button variant="ghost" size="sm" onClick={() => show({ kind: 'app', tab: 'processes' })}>
                                <Plus /> Process
                            </Button>
                        )}
                        {addResource}
                    </div>
                )}
            </div>

            {hasPods && <CapacityBar clusterName={cluster.name} capacity={capacity} requests={status?.requests ?? null} />}

            <section className="space-y-3">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="text-base font-medium">Latest deployments</h2>
                    <Link href={route('environments.deployments', environment.id)} className="text-muted-foreground hover:text-foreground text-sm">
                        View all
                    </Link>
                </div>
                {deployments.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No deployments yet. Select Deploy to start the first one.</p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {deployments.map((deployment) => (
                            <li key={deployment.id}>
                                <Link
                                    href={route('deployments.show', deployment.id)}
                                    className="hover:bg-muted/50 flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                                >
                                    <DeploymentBadge status={deployment.status} />
                                    <code className="text-muted-foreground font-mono text-xs">{shortSha(deployment.commit_sha)}</code>
                                    <span>{deployment.triggered_by ?? 'Automatic'}</span>
                                    <span className="text-muted-foreground ml-auto">
                                        {isRunning(deployment.status) ? 'Running' : duration(deployment.started_at, deployment.finished_at)},{' '}
                                        {timeAgo(deployment.created_at)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <HttpsSheet open={open && sheet.kind === 'https'} onClose={close} props={props} />
            <DomainsSheet
                open={open && sheet.kind === 'domains'}
                onClose={close}
                focusAdd={sheet.kind === 'domains' && sheet.adding}
                environmentId={environment.id}
                domains={domains}
                ingressIp={cluster.ingress_ip}
                cloudflareZones={props.cloudflare_zones}
                canManage={can.manage_domains}
            />
            <AppSheet
                open={open && sheet.kind === 'app'}
                onClose={close}
                tab={sheet.kind === 'app' ? sheet.tab : 'general'}
                onTab={(tab) => setSheet({ kind: 'app', tab })}
                environmentId={environment.id}
                clusterName={cluster.name}
                compute={compute}
                sizes={sizes}
                processes={processes}
                canUpdate={can.update}
                canDeploy={can.deploy && hasPods}
            />
            <ResourceSheet
                open={open && sheet.kind === 'resource'}
                onClose={close}
                attachment={sheet.kind === 'resource' ? datastores.attachments.find((attachment) => attachment.id === sheet.id) : undefined}
                environmentId={environment.id}
                datastores={datastores}
                canUpdate={can.update}
                canDeploy={can.deploy && hasPods}
                canRestore={can.restore}
                canManageCredentials={can.manage_credentials}
            />
        </EnvironmentLayout>
    );
}

/** A line between two columns of the canvas, at the height of the first block header. */
function Connector() {
    return (
        <div aria-hidden className="relative hidden @4xl:block">
            <span className="bg-foreground/20 absolute inset-x-0 top-[3.65rem] h-px" />
        </div>
    );
}
