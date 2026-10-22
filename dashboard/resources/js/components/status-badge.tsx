import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type DeploymentStatus, type DnsStatus } from '@/types';

const deploymentStyles: Record<DeploymentStatus, string> = {
    queued: 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    building: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    deploying: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    live: 'border-transparent bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    superseded: 'border-transparent bg-muted text-muted-foreground',
};

const deploymentLabels: Record<DeploymentStatus, string> = {
    queued: 'Queued',
    building: 'Building',
    deploying: 'Deploying',
    live: 'Live',
    failed: 'Failed',
    superseded: 'Replaced',
};

export function DeploymentBadge({ status }: { status: DeploymentStatus | null }) {
    if (!status) {
        return <Badge variant="outline">Not deployed</Badge>;
    }

    return <Badge className={cn(deploymentStyles[status])}>{deploymentLabels[status]}</Badge>;
}

const dnsStyles: Record<DnsStatus, string> = {
    pending: 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    ok: 'border-transparent bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    proxied: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    wrong: 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    missing: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
};

const dnsLabels: Record<DnsStatus, string> = {
    pending: 'Pending',
    ok: 'DNS ok',
    proxied: 'Proxied',
    wrong: 'Wrong address',
    missing: 'No record',
};

export function DnsBadge({ status }: { status: DnsStatus }) {
    return <Badge className={cn(dnsStyles[status])}>{dnsLabels[status]}</Badge>;
}

export const isRunning = (status: DeploymentStatus | null) => status === 'queued' || status === 'building' || status === 'deploying';
