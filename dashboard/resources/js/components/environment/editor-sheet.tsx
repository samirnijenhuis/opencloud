import DeployButton from '@/components/deploy-button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/** The frame of every sheet on the canvas: a header, optional tabs, a body that scrolls, and a footer. */
export function EditorSheet({
    open,
    onClose,
    title,
    description,
    tabs,
    footer,
    children,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: ReactNode;
    tabs?: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
                <SheetHeader className={cn('space-y-1 px-6 pt-5 pr-12 text-left', tabs ? 'pb-0' : 'border-b pb-5')}>
                    <SheetTitle className="text-base font-medium">{title}</SheetTitle>
                    <SheetDescription>{description}</SheetDescription>
                </SheetHeader>
                {tabs}
                <div className="flex-1 space-y-6 overflow-y-auto px-6 py-5">{children}</div>
                {footer && <div className="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3">{footer}</div>}
            </SheetContent>
        </Sheet>
    );
}

export function SheetTabs<T extends string>({
    value,
    onChange,
    items,
}: {
    value: T;
    onChange: (value: T) => void;
    items: { value: T; label: string }[];
}) {
    return (
        <div role="tablist" className="mt-3 flex gap-5 border-b px-6">
            {items.map((item) => (
                <button
                    key={item.value}
                    type="button"
                    role="tab"
                    aria-selected={item.value === value}
                    onClick={() => onChange(item.value)}
                    className={cn(
                        'focus-visible:ring-ring -mb-px border-b-2 pb-2.5 text-sm outline-none focus-visible:ring-2',
                        item.value === value ? 'border-foreground text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {item.label}
                </button>
            ))}
        </div>
    );
}

/** The usual footer: when the change takes effect, then the actions. */
export function NextDeployFooter({ environmentId, canDeploy, children }: { environmentId: number; canDeploy: boolean; children?: ReactNode }) {
    return (
        <>
            <span className="text-muted-foreground text-xs">Changes apply on the next deploy.</span>
            <div className="flex gap-2">
                {children}
                {canDeploy && <DeployButton environmentId={environmentId} variant="secondary" />}
            </div>
        </>
    );
}
