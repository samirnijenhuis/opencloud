import { Status, type Tone } from '@/components/environment/block';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePoll } from '@/hooks/use-poll';
import EnvironmentLayout from '@/layouts/environment-layout';
import { duration, shortSha, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type EnvironmentPageProps } from '@/types';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

type RunStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'timed_out';

type Run = {
    id: number;
    command: string;
    status: RunStatus;
    exit_code: number | null;
    user: string | null;
    commit_sha: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
};

type Props = EnvironmentPageProps & { can: EnvironmentPageProps['can'] & { read_logs: boolean } };

const runStates: Record<RunStatus, [Tone, string]> = {
    queued: ['off', 'Queued'],
    running: ['warn', 'Running'],
    succeeded: ['ok', 'Succeeded'],
    failed: ['bad', 'Failed'],
    timed_out: ['bad', 'Timed out'],
};

const finished = (status: RunStatus) => status === 'succeeded' || status === 'failed' || status === 'timed_out';

function RunStatusText({ status }: { status: RunStatus }) {
    const [tone, label] = runStates[status];

    return <Status tone={tone}>{label}</Status>;
}

/** Laravel reads the XSRF-TOKEN cookie back from this header. */
const xsrfToken = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

function RunForm({ environmentId, onStarted }: { environmentId: number; onStarted: (run: Run) => void }) {
    const [command, setCommand] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [starting, setStarting] = useState(false);

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setStarting(true);
        setError(null);

        try {
            const response = await fetch(route('command-runs.store', environmentId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ command }),
            });
            const body = await response.json();

            if (response.status === 201) {
                setCommand('');
                onStarted(body.run as Run);
            } else {
                setError(body.errors?.command?.[0] ?? body.message ?? 'The command did not start. Try again.');
            }
        } catch {
            setError('The command did not start. Check the connection and try again.');
        } finally {
            setStarting(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-2">
            <div className="flex gap-2">
                <Input
                    aria-label="Command"
                    className="font-mono text-sm"
                    placeholder="php artisan migrate:status"
                    value={command}
                    onChange={(e) => setCommand(e.target.value)}
                />
                <Button disabled={starting || command.trim() === ''}>{starting ? 'Starting' : 'Run'}</Button>
            </div>
            <InputError message={error ?? undefined} />
            <p className="text-muted-foreground text-sm">
                The command runs in a new pod with the image and the env values of the live deployment. It stops after 15 minutes.
            </p>
        </form>
    );
}

function Output({ runId }: { runId: number }) {
    const [body] = usePoll<{ run: Run & { output: string } }>(route('command-runs.show', runId), 2000, (data) => !finished(data.run.status));
    const box = useRef<HTMLPreElement>(null);
    const run = body?.run;

    useEffect(() => {
        if (box.current) {
            box.current.scrollTop = box.current.scrollHeight;
        }
    }, [run?.output]);

    if (!run) {
        return <p className="text-muted-foreground text-sm">Loading the output.</p>;
    }

    const text = run.output.trim() !== '' ? run.output : finished(run.status) ? 'The command wrote no output.' : 'Waiting for the first output.';

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <RunStatusText status={run.status} />
                {run.exit_code !== null && <span className="text-muted-foreground">Exit code {run.exit_code}</span>}
                {run.commit_sha && <code className="text-muted-foreground font-mono text-xs">{shortSha(run.commit_sha)}</code>}
                {run.started_at && <span className="text-muted-foreground">{duration(run.started_at, run.finished_at)}</span>}
            </div>
            <code className="block font-mono text-sm break-all">{run.command}</code>
            <pre
                ref={box}
                aria-label="Output of the command"
                className="h-[26rem] overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-neutral-100"
            >
                {text}
            </pre>
        </div>
    );
}

export default function EnvironmentCommands(props: Props) {
    const { environment, can } = props;
    const [selected, setSelected] = useState<number | null>(null);
    const [body, refresh] = usePoll<{ runs: Run[] }>(can.read_logs ? route('environments.command-runs', environment.id) : null, 5000, (data) =>
        data.runs.some((run) => !finished(run.status)),
    );
    const runs = body?.runs ?? null;
    const current = selected ?? runs?.[0]?.id ?? null;

    if (!can.read_logs) {
        return (
            <EnvironmentLayout page={props} tab="commands" title="Commands">
                <p className="text-muted-foreground text-sm">The output of a command is like a log. Only a member who can read logs can see it.</p>
            </EnvironmentLayout>
        );
    }

    return (
        <EnvironmentLayout page={props} tab="commands" title="Commands">
            {can.update ? (
                <RunForm
                    environmentId={environment.id}
                    onStarted={(run) => {
                        setSelected(run.id);
                        refresh();
                    }}
                />
            ) : (
                <p className="text-muted-foreground text-sm">Only an admin or an owner can run commands on a protected environment.</p>
            )}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <section className="space-y-3">
                    <h2 className="text-base font-medium">History</h2>
                    {runs === null ? (
                        <p className="text-muted-foreground text-sm">Loading the history.</p>
                    ) : runs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No command has run on this environment yet.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {runs.map((run) => (
                                <li key={run.id}>
                                    <button
                                        type="button"
                                        aria-current={run.id === current}
                                        onClick={() => setSelected(run.id)}
                                        className={cn(
                                            'focus-visible:ring-ring w-full space-y-1 px-4 py-3 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-inset',
                                            run.id === current ? 'bg-muted' : 'hover:bg-muted/50',
                                        )}
                                    >
                                        <code className="block truncate font-mono text-xs">{run.command}</code>
                                        <span className="text-muted-foreground flex flex-wrap items-center gap-x-3 text-xs">
                                            <RunStatusText status={run.status} />
                                            <span>{run.user ?? 'A removed member'}</span>
                                            <span>{timeAgo(run.created_at)}</span>
                                            {finished(run.status) && <span>{duration(run.started_at, run.finished_at)}</span>}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="min-w-0 space-y-3">
                    <h2 className="text-base font-medium">Output</h2>
                    {current === null ? (
                        <p className="text-muted-foreground text-sm">Select a command to see its output.</p>
                    ) : (
                        <Output key={current} runId={current} />
                    )}
                </section>
            </div>
        </EnvironmentLayout>
    );
}
