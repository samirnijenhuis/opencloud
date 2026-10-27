import { type ClusterKind } from '@/pages/clusters/cluster-status';
import { type Deployment, type DnsStatus, type EnvironmentPageProps, type Process, type Size, type SizeOption } from '@/types';

export type Domain = {
    id: number;
    hostname: string;
    /** The free sslip.io address that opencloud adds by itself. */
    generated: boolean;
    dns_status: DnsStatus;
    dns_mode: 'manual' | 'cloudflare';
    dns_found: string[];
    certificate_status: string;
    dns_checked_at: string | null;
};

export type DatastoreKind = 'mysql' | 'postgres' | 'valkey' | 'rustfs';

export type DatastoreStatus = 'pending' | 'provisioning' | 'ready' | 'failed' | 'destroying';

export type BackupRow = {
    id: number;
    status: 'running' | 'done' | 'failed';
    size: number | null;
    location: 'local' | 's3';
    error: string | null;
    created_at: string;
};

export type Restore = { status: 'running' | 'restored' | 'failed'; at: string; error: string | null };

/** Standard runs in the cluster of the app, managed runs at a cloud provider, and external is a server that the user runs. */
export type DatastoreSource = 'standard' | 'managed' | 'external';

export type ResourceFamily = 'database' | 'key_value' | 'bucket';

export type Attachment = {
    id: number;
    kind: DatastoreKind;
    source: DatastoreSource;
    family: ResourceFamily;
    /** The name that the backend gives it, for example "Postgres" or "External MySQL". */
    label: string;
    /** The name of a database or a bucket. A key-value store, and a managed one that is being destroyed, have none. */
    name: string | null;
    /** The default of a family sets the default env values. Each other database or bucket sets its own values. */
    is_default: boolean;
    /** The line for the database or disk config of the app, for one that is not the default, or null. */
    config_line: string | null;
    status: DatastoreStatus;
    error: string | null;
    /** The cluster, the cloud provider, or the host of a server that the user runs. */
    runs_on: string;
    /** The cluster is null for a server that the user runs. The version is empty when opencloud does not know it. */
    server: { cluster: string | null; version: string; status: DatastoreStatus; error: string | null };
    env_keys: string[];
    /** Why this user cannot detach it, beyond the rights on the environment, or null. */
    detach_refusal: string | null;
    /** Only a SQL database in the cluster has backups. */
    backups?: BackupRow[];
    restore?: Restore | null;
    managed?: boolean;
    tofu_log?: string | null;
};

/** One field of the address of a server that the user runs. A secret field never comes back from the backend. */
export type ConnectionField = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'secret' | 'toggle';
    required: boolean;
    value: string | number | boolean | null;
    placeholder: string | null;
    help: string | null;
};

/** One kind from one source that the add menu shows. The backend registry decides the list and every text in it. */
export type Offer = {
    kind: DatastoreKind;
    source: DatastoreSource;
    family: ResourceFamily;
    /** The name field of a database or a bucket, or null for a key-value store. The first one of a family starts as "main". */
    name_field: { label: string; placeholder: string; value: string; description: string } | null;
    label: string;
    description: string;
    /** The heading of the source in the menu. */
    group: string;
    /** What the add dialog says about the source, or null when the source needs no dialog. */
    note: string | null;
    /** Why this user cannot add it here, or null. */
    refusal: string | null;
    /** The address fields of a server that the user runs. Empty for the other sources. */
    fields: ConnectionField[];
};

export type Datastores = {
    attachments: Attachment[];
    offers: Offer[];
    has_backup_target: boolean;
    polling: boolean;
};

export type Compute = { size: Size; min_instances: number; max_instances: number; cpu_target: number; health_path: string };

export type CanvasProps = EnvironmentPageProps & {
    can: EnvironmentPageProps['can'] & { restore: boolean; manage_credentials: boolean };
    cluster: { id: number; name: string; kind: ClusterKind; ingress_ip: string | null };
    tls_issuer: 'acme' | 'internal';
    compute: Compute;
    sizes: SizeOption[];
    processes: Process[];
    domains: Domain[];
    cloudflare_zones: string[];
    datastores: Datastores;
    deployments: Deployment[];
};

export type Resources = { cpu_millicores: number; memory_mib: number };

/** CPU and memory are null when the cluster has no metrics server. */
export type ProcessStatus = {
    name: string;
    desired: number;
    ready: number;
    restarts: number;
    cpu_millicores: number | null;
    memory_mib: number | null;
};

/** The last run of a scheduled process. A process that never ran has no time. */
export type ScheduledStatus = { name: string; last_run_at: string | null; last_run_failed: boolean };

export type LiveStatus = {
    state: 'ok' | 'unknown';
    error: string | null;
    processes: ProcessStatus[];
    scheduled: ScheduledStatus[];
    requests: Resources | null;
    checked_at: string;
};

export type Capacity = {
    state: 'ok' | 'unknown';
    error: string | null;
    nodes: number | null;
    allocatable: Resources | null;
    requested: Resources | null;
    checked_at: string;
};

export type AppTab = 'general' | 'autoscaling';

/** The open side sheet. Only one is open at a time. A process sheet without an id adds a new process. */
export type Sheet =
    | { kind: 'https' }
    | { kind: 'domains'; adding: boolean }
    | { kind: 'app'; tab: AppTab }
    | { kind: 'process'; id: number | null; tab: AppTab }
    | { kind: 'resource'; id: number };
