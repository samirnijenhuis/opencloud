import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type ClusterKind = 'local' | 'connected' | 'created';

export type ClusterStatus = 'provisioning' | 'pending' | 'installing' | 'ready' | 'failed' | 'destroying';

export const kindLabels: Record<ClusterKind, string> = {
    local: 'Local, on the dashboard server',
    connected: 'Connected',
    created: 'Created by opencloud',
};

export const busyStatuses: ClusterStatus[] = ['provisioning', 'installing', 'destroying'];

const styles: Record<ClusterStatus, string> = {
    provisioning: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    pending: 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    installing: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    ready: 'border-transparent bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    destroying: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
};

const labels: Record<ClusterStatus, string> = {
    provisioning: 'Creating',
    pending: 'Pending',
    installing: 'Installing',
    ready: 'Ready',
    failed: 'Failed',
    destroying: 'Destroying',
};

export function ClusterBadge({ status }: { status: ClusterStatus }) {
    return <Badge className={cn(styles[status])}>{labels[status]}</Badge>;
}
