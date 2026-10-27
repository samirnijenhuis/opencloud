import { cn } from '@/lib/utils';
import { createContext, type ReactNode, useContext, useLayoutEffect, useRef, useState } from 'react';

export type CanvasColumn = 'network' | 'cluster' | 'resources';

/** A node is a block. An edge is a pair of node ids: the block at the left end and the block at the right end. */
export type CanvasNode = { id: string; column: CanvasColumn; target: boolean };
export type CanvasEdge = readonly [from: string, to: string];

/**
 * Each network block reaches the HTTP target of the cluster. Each block of the cluster reaches each resource.
 * A new block needs no registration: it joins by its column.
 */
export function canvasEdges(nodes: CanvasNode[]): CanvasEdge[] {
    const column = (name: CanvasColumn) => nodes.filter((node) => node.column === name);
    const targets = column('cluster').filter((node) => node.target);

    return [
        ...column('network').flatMap((node) => targets.map((target): CanvasEdge => [node.id, target.id])),
        ...column('cluster').flatMap((node) => column('resources').map((resource): CanvasEdge => [node.id, resource.id])),
    ];
}

type Line = { key: string; from: string; to: string; d: string };

type Highlight = { lit: ReadonlySet<string>; setActive: (id: string | null) => void };

const CanvasContext = createContext<Highlight>({ lit: new Set(), setActive: () => {} });

/** The ids of the blocks that the hover or the focus lights: the active block and the blocks at the other end of its lines. */
export const useCanvas = () => useContext(CanvasContext);

const sameLines = (a: Line[], b: Line[]) => a.length === b.length && a.every((line, i) => line.key === b[i].key && line.d === b[i].d);

/** Measures the blocks that are in the DOM, so a new block gets its lines without a change here. */
function measure(container: HTMLElement): Line[] {
    const groups = [...container.querySelectorAll<HTMLElement>('[data-column]')];

    // Below the container breakpoint the groups stack in one column and have no lines.
    if (new Set(groups.map((group) => Math.round(group.getBoundingClientRect().left))).size < 2) return [];

    const origin = container.getBoundingClientRect();
    const spans = new Map(
        groups.map((group) => {
            const rect = group.getBoundingClientRect();

            return [group.dataset.column, { left: rect.left - origin.left, right: rect.right - origin.left }] as const;
        }),
    );
    const elements = new Map<string, HTMLElement>();
    const nodes = [...container.querySelectorAll<HTMLElement>('[data-block]')].flatMap((element): CanvasNode[] => {
        const column = element.closest<HTMLElement>('[data-column]')?.dataset.column as CanvasColumn | undefined;
        if (!column) return [];

        elements.set(element.dataset.block!, element);

        return [{ id: element.dataset.block!, column, target: element.dataset.httpTarget !== undefined }];
    });
    const columns = new Map(nodes.map((node) => [node.id, node.column]));
    const edge = (id: string) => {
        const element = elements.get(id)!;
        const rect = element.getBoundingClientRect();
        const header = element.querySelector('[data-block-header]')?.getBoundingClientRect() ?? rect;

        return { left: rect.left - origin.left, right: rect.right - origin.left, y: header.top + header.height / 2 - origin.top };
    };

    return canvasEdges(nodes).flatMap(([from, to]) => {
        const gap = [spans.get(columns.get(from)), spans.get(columns.get(to))];
        if (!gap[0] || !gap[1]) return [];

        const start = edge(from);
        const end = edge(to);
        const bus = (gap[0].right + gap[1].left) / 2;

        return [{ key: `${from}:${to}`, from, to, d: `M${start.right} ${start.y} H${bus} V${end.y} H${end.left}` }];
    });
}

/**
 * The frame of the canvas. It draws the lines behind the blocks as one SVG layer, and it draws them again when the
 * size or the block list changes. A hover or a focus on a block lights that block, its lines, and the blocks at their other end.
 */
export function Canvas({ className, children }: { className?: string; children: ReactNode }) {
    const container = useRef<HTMLDivElement>(null);
    const [lines, setLines] = useState<Line[]>([]);
    const [active, setActive] = useState<string | null>(null);

    // No deps: each render of the page can add or remove a block, so the effect finds the blocks again.
    useLayoutEffect(() => {
        const element = container.current!;
        const draw = () => {
            const next = measure(element);
            setLines((current) => (sameLines(current, next) ? current : next));
        };
        const observer = new ResizeObserver(draw);

        observer.observe(element);
        element.querySelectorAll('[data-block]').forEach((block) => observer.observe(block));
        draw();

        return () => observer.disconnect();
    });

    const touching = lines.filter((line) => line.from === active || line.to === active);
    const lit = new Set(active === null || touching.length === 0 ? [] : [active, ...touching.flatMap((line) => [line.from, line.to])]);

    return (
        <CanvasContext.Provider value={{ lit, setActive }}>
            <div ref={container} className={cn('relative', className)}>
                <svg aria-hidden className="text-foreground/25 pointer-events-none absolute inset-0 z-0 size-full overflow-visible">
                    {lines.map((line) => (
                        <path key={line.key} d={line.d} fill="none" stroke="currentColor" strokeWidth={1.5} />
                    ))}
                    {touching.map((line) => (
                        <path key={`lit-${line.key}`} d={line.d} fill="none" stroke="var(--color-blue-500)" strokeWidth={1.5} />
                    ))}
                </svg>
                {children}
            </div>
        </CanvasContext.Provider>
    );
}
