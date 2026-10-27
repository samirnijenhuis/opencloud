import { duration } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type DeploymentStep, type DeploymentStepStatus } from '@/types';
import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const statusLabels: Record<DeploymentStepStatus, string> = { finished: 'Finished', running: 'Running', failed: 'Failed', skipped: 'Skipped' };

const statusStyles: Record<DeploymentStepStatus, string> = {
    finished: 'text-muted-foreground',
    running: 'text-blue-700 dark:text-blue-300',
    failed: 'text-red-700 dark:text-red-300',
    skipped: 'text-muted-foreground',
};

/** 3 gives "00:00:03". */
const clock = (seconds: number) =>
    [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60].map((part) => String(part).padStart(2, '0')).join(':');

function StepLines({ step }: { step: DeploymentStep }) {
    const box = useRef<HTMLDivElement>(null);
    const following = useRef(true);

    useEffect(() => {
        const element = box.current;

        if (element && following.current && step.status === 'running') {
            element.scrollTop = element.scrollHeight;
        }
    }, [step.lines.length, step.status]);

    const onScroll = () => {
        const element = box.current;

        if (element) {
            following.current = element.scrollHeight - element.scrollTop - element.clientHeight < 40;
        }
    };

    return (
        <div ref={box} onScroll={onScroll} className="max-h-96 overflow-auto border-t px-4 py-3 font-mono text-xs leading-6">
            {step.lines.length === 0 ? (
                <p className="text-muted-foreground">{step.status === 'skipped' ? 'This step did not run.' : 'This step wrote no lines yet.'}</p>
            ) : (
                step.lines.map((line, index) => (
                    <div key={index} className="flex gap-4">
                        <span className="text-muted-foreground shrink-0 select-none">{clock(line.offset)}</span>
                        <span className="break-all whitespace-pre-wrap">{line.text}</span>
                    </div>
                ))
            )}
        </div>
    );
}

function StepRow({ step, open, onToggle }: { step: DeploymentStep; open: boolean; onToggle: () => void }) {
    return (
        <div>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="hover:bg-muted/50 flex w-full items-center gap-4 px-4 py-3 text-left text-sm"
            >
                <span className="min-w-0 flex-1 break-words">{step.title}</span>
                <span className={cn('font-mono text-xs', statusStyles[step.status])}>{statusLabels[step.status]}</span>
                <span className="text-muted-foreground w-24 text-right font-mono text-xs whitespace-nowrap">
                    {step.started_at ? duration(step.started_at, step.finished_at) : ''}
                </span>
                <ChevronDown className={cn('text-muted-foreground size-4 shrink-0 transition-transform', open && 'rotate-180')} />
            </button>
            {open && <StepLines step={step} />}
        </div>
    );
}

function StepCard({ title, steps }: { title: string; steps: DeploymentStep[] }) {
    // The user's choice wins. Without one, the running step and a failed step are open.
    const [chosen, setChosen] = useState<Record<string, boolean>>({});

    if (steps.length === 0) {
        return null;
    }

    return (
        <section aria-label={title} className="bg-muted/30 rounded-lg border">
            <h2 className="px-4 py-3 text-sm font-medium">{title}</h2>
            <div className="bg-background divide-y overflow-hidden rounded-lg border-t">
                {steps.map((step) => {
                    const id = step.key ?? 'log';
                    const open = chosen[id] ?? (step.status === 'running' || step.status === 'failed');

                    return <StepRow key={id} step={step} open={open} onToggle={() => setChosen({ ...chosen, [id]: !open })} />;
                })}
            </div>
        </section>
    );
}

export function DeploymentSteps({ steps, running }: { steps: DeploymentStep[]; running: boolean }) {
    if (steps.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">{running ? 'Waiting for the first line of the log.' : 'This deployment wrote no log.'}</p>
        );
    }

    // A log from before the steps has one step without a key.
    if (steps[0].key === null) {
        return <StepCard title="Deployment log" steps={steps} />;
    }

    return (
        <div className="space-y-6">
            <StepCard title="Build logs" steps={steps.filter((step) => step.phase === 'build')} />
            <StepCard title="Deployment logs" steps={steps.filter((step) => step.phase === 'deploy')} />
        </div>
    );
}
