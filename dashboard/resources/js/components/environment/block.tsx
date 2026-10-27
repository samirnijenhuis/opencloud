import { type CanvasColumn, useCanvas } from '@/components/environment/canvas';
import { cn } from '@/lib/utils';
import { type LucideIcon, Plus } from 'lucide-react';
import { type ReactNode, useId } from 'react';

export type Tone = 'ok' | 'warn' | 'bad' | 'off';

const toneStyles: Record<Tone, string> = {
    ok: 'text-green-700 dark:text-green-400',
    warn: 'text-amber-700 dark:text-amber-400',
    bad: 'text-red-700 dark:text-red-400',
    off: 'text-muted-foreground',
};

/** A status word with a colored dot. */
export function Status({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap', toneStyles[tone])}>
            <span aria-hidden className="size-1.5 shrink-0 rounded-full bg-current" />
            {children}
        </span>
    );
}

/** A labeled area on the canvas, for example the network or a cluster. */
export function Group({
    label,
    aside,
    column,
    children,
    className,
}: {
    label: ReactNode;
    aside?: ReactNode;
    column: CanvasColumn;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section data-column={column} className={cn('bg-muted/80 border-border/70 space-y-2.5 rounded-xl border p-2.5', className)}>
            <header className="text-muted-foreground flex items-center justify-between gap-2 px-1.5 pt-0.5 text-xs">
                <span className="truncate">{label}</span>
                {aside && <span className="shrink-0">{aside}</span>}
            </header>
            {children}
        </section>
    );
}

/**
 * A card that opens its sheet. The header is the button, so the add buttons inside stay separate controls.
 * The canvas draws the lines of a block. `target` marks the block that takes the HTTP traffic.
 */
export function Block({
    icon: Icon,
    title,
    aside,
    target,
    onOpen,
    children,
}: {
    icon: LucideIcon;
    title: string;
    aside?: ReactNode;
    target?: boolean;
    onOpen: () => void;
    children?: ReactNode;
}) {
    const id = useId();
    const { lit, setActive } = useCanvas();

    return (
        <div
            data-block={id}
            data-http-target={target ? '' : undefined}
            onClick={onOpen}
            onPointerEnter={() => setActive(id)}
            onPointerLeave={() => setActive(null)}
            onFocus={() => setActive(id)}
            onBlur={() => setActive(null)}
            className={cn(
                'bg-card relative z-[1] cursor-pointer overflow-hidden rounded-lg border shadow-xs transition-colors',
                lit.has(id) ? 'border-blue-500' : 'hover:border-foreground/25',
            )}
        >
            <button
                type="button"
                data-block-header
                onClick={(e) => {
                    e.stopPropagation();
                    onOpen();
                }}
                className="focus-visible:ring-ring flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset"
            >
                <Icon className="text-muted-foreground size-4 shrink-0" />
                <span className="min-w-0 truncate">{title}</span>
                {aside && <span className="ml-auto shrink-0 text-xs font-normal">{aside}</span>}
            </button>
            {children && <div className="border-t py-1">{children}</div>}
        </div>
    );
}

export function Row({ label, children, className }: { label: ReactNode; children: ReactNode; className?: string }) {
    return (
        <div className={cn('flex items-center justify-between gap-3 px-3 py-1.5 text-sm', className)}>
            <span className="text-muted-foreground min-w-0 truncate">{label}</span>
            <span className="max-w-[70%] shrink-0 truncate text-right">{children}</span>
        </div>
    );
}

export function SubHeading({ children }: { children: ReactNode }) {
    return <div className="text-muted-foreground mt-1 border-t px-3 pt-2 pb-1 text-xs">{children}</div>;
}

export function AddButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring flex w-full items-center gap-1.5 rounded-md px-3 py-1.5 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-inset"
        >
            <Plus className="size-3.5" />
            {children}
        </button>
    );
}
