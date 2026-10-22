import { Button } from '@/components/ui/button';
import EnvironmentLayout from '@/layouts/environment-layout';
import { type EnvironmentPageProps } from '@/types';
import { useEffect, useRef, useState } from 'react';

type Props = EnvironmentPageProps & { processes: string[] };
type Result = { log: string; error: string | null };

const REFRESH_MS = 5000;

export default function EnvironmentLogs(props: Props) {
    const { environment, processes } = props;
    const [process, setProcess] = useState(processes[0] ?? 'web');
    const [result, setResult] = useState<Result | null>(null);
    const [failed, setFailed] = useState(false);
    const box = useRef<HTMLPreElement>(null);
    const following = useRef(true);

    useEffect(() => {
        let active = true;
        setResult(null);

        const load = async () => {
            try {
                const response = await fetch(`${route('environments.logs.read', environment.id)}?process=${process}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const body = (await response.json()) as Result;

                if (active) {
                    setResult(body);
                    setFailed(false);
                }
            } catch {
                if (active) {
                    setFailed(true);
                }
            }
        };

        load();
        const timer = window.setInterval(load, REFRESH_MS);

        return () => {
            active = false;
            window.clearInterval(timer);
        };
    }, [environment.id, process]);

    useEffect(() => {
        const element = box.current;

        if (element && following.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [result]);

    const onScroll = () => {
        const element = box.current;

        if (element) {
            following.current = element.scrollHeight - element.scrollTop - element.clientHeight < 40;
        }
    };

    const text = result === null ? 'Loading the logs.' : (result.error ?? (result.log.trim() === '' ? 'There are no log lines yet.' : result.log));

    return (
        <EnvironmentLayout page={props} tab="logs" title="Logs">
            <div className="flex flex-wrap items-center gap-2">
                {processes.map((name) => (
                    <Button key={name} size="sm" variant={name === process ? 'default' : 'secondary'} onClick={() => setProcess(name)}>
                        {name}
                    </Button>
                ))}
                <span className="text-muted-foreground ml-auto text-xs">Refreshes every 5 seconds</span>
            </div>

            {failed && <p className="text-sm text-red-600 dark:text-red-400">We could not load the logs. We try again in a few seconds.</p>}

            <pre
                ref={box}
                onScroll={onScroll}
                aria-label={`Logs of the ${process} process`}
                className="h-[32rem] overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-neutral-100"
            >
                {text}
            </pre>
        </EnvironmentLayout>
    );
}
