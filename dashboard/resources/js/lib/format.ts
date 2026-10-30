import { type Size, type SizeOption } from '@/types';

export const shortSha = (sha: string) => sha.slice(0, 7);

export function timeAgo(iso: string | null, now: number = Date.now()): string {
    if (!iso) {
        return 'Never';
    }

    const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));

    if (seconds < 45) return 'Just now';
    if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)} h ago`;

    return `${Math.round(seconds / 86400)} d ago`;
}

/** The time between two ISO strings. An unfinished run counts up to now. */
export function duration(start: string | null, end: string | null, now: number = Date.now()): string {
    if (!start) {
        return '-';
    }

    const seconds = Math.max(0, Math.round(((end ? new Date(end).getTime() : now) - new Date(start).getTime()) / 1000));

    return seconds < 60 ? `${seconds} s` : `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}

/** 1250 millicores give "1.25". */
export const vcpu = (millicores: number) => String(Math.round(millicores / 10) / 100);

export const memoryText = (mib: number) => (mib >= 1024 ? `${Math.round((mib / 1024) * 10) / 10} GB` : `${mib} MB`);

export const sizeText = (size: SizeOption) => `${vcpu(size.cpu_millicores)} vCPU, ${memoryText(size.memory_mib)}`;

export const sizeLabel = (value: Size) => value.toUpperCase();
