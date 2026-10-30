import { cn } from '@/lib/utils';

export function Chip({ selected, onClick, children }: { selected: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'rounded-md border px-3 py-2 text-left text-sm transition-colors disabled:pointer-events-none disabled:opacity-60',
                selected ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}
