import { cn } from '@/lib/utils';
import { type EnvironmentColor } from '@/types';

export const environmentColors: Record<EnvironmentColor, string> = {
    blue: 'bg-blue-500',
    green: 'bg-green-500',
    orange: 'bg-orange-500',
    purple: 'bg-purple-500',
    red: 'bg-red-500',
    yellow: 'bg-yellow-400',
    sky: 'bg-sky-400',
    gray: 'bg-zinc-400',
};

export function ColorDot({ color, className }: { color: EnvironmentColor; className?: string }) {
    return <span aria-hidden className={cn('inline-block size-2.5 shrink-0 rounded-[3px]', environmentColors[color], className)} />;
}
