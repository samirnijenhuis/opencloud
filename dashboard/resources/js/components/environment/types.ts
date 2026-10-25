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

export type Attachment = {
    id: number;
    kind: DatastoreKind;
    /** The connection name of a MySQL or Postgres database. Valkey, RustFS, and a database that DigitalOcean destroys have none. */
    name: string | null;
    /** The default database sets the DB_* values. Each other database sets <NAME>_DB_URL. */
    is_default: boolean;
    /** The line for config/database.php, for a database that is not the default. */
    config_line: string | null;
    status: DatastoreStatus;
    error: string | null;
    server: { cluster: string; version: string; status: DatastoreStatus; error: string | null };
    env_keys: string[];
    /** Only a SQL database in the cluster has backups. */
    backups?: BackupRow[];
    restore?: Restore | null;
    managed?: boolean;
    tofu_log?: string | null;
};

export type Datastores = {
    attachments: Attachment[];
    available_kinds: DatastoreKind[];
    has_backup_target: boolean;
    polling: boolean;
    managed: { available: boolean; pricing_url: string; size: string };
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

export type LiveStatus = {
    state: 'ok' | 'unknown';
    error: string | null;
    processes: ProcessStatus[];
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

export type AppTab = 'general' | 'autoscaling' | 'processes';

/** The open side sheet. Only one is open at a time. */
export type Sheet = { kind: 'https' } | { kind: 'domains'; adding: boolean } | { kind: 'app'; tab: AppTab } | { kind: 'resource'; id: number };

export const kindLabels: Record<DatastoreKind, string> = { mysql: 'MySQL', postgres: 'Postgres', valkey: 'Valkey', rustfs: 'RustFS' };

export const sqlKinds: DatastoreKind[] = ['mysql', 'postgres'];
