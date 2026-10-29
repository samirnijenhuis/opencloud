import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import EnvironmentLayout from '@/layouts/environment-layout';
import { type EnvironmentPageProps } from '@/types';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

type Props = EnvironmentPageProps & { processes: string[] };
type Line = { time: string; pod: string; line: string };
type Result = { lines: Line[]; truncated: boolean; error: string | null };

const REFRESH_MS = 5000;

// The log store keeps the lines for 7 days.
const RANGES: Record<string, string> = {
    '900': 'Last 15 minutes',
    '3600': 'Last hour',
    '21600': 'Last 6 hours',
    '86400': 'Last 24 hours',
    '604800': 'Last 7 days',
    custom: 'Custom range',
};

/** The value of a datetime-local input, which has no time zone. */
const localInput = (date: Date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

const shortTime = (time: string) =>
    new Date(time).toLocaleString(undefined, {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

export default function EnvironmentLogs(props: Props) {
    const { environment, processes } = props;
    const [process, setProcess] = useState(processes[0] ?? 'web');
    const [range, setRange] = useState('3600');
    const [customFrom, setCustomFrom] = useState('');
    const [customTo, setCustomTo] = useState('');
    const [searchText, setSearchText] = useState('');
    const [search, setSearch] = useState('');
    const [result, setResult] = useState<Result | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const box = useRef<HTMLPreElement>(null);
    const following = useRef(true);

    useEffect(() => {
        let active = true;
        setResult(null);
        setProblem(null);

        const load = async () => {
            const to = range === 'custom' ? new Date(customTo) : new Date();
            const from = range === 'custom' ? new Date(customFrom) : new Date(to.getTime() - Number(range) * 1000);

            if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) {
                setProblem('Pick the start and the end of the range.');

                return;
            }

            const query = new URLSearchParams({ process, from: from.toISOString(), to: to.toISOString(), ...(search === '' ? {} : { search }) });

            try {
                const response = await fetch(`${route('environments.logs.read', environment.id)}?${query}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (response.status === 422) {
                    const body = (await response.json()) as { message: string };

                    if (active) {
                        setProblem(body.message);
                    }

                    return;
                }

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const body = (await response.json()) as Result;

                if (active) {
                    setResult(body);
                    setProblem(null);
                }
            } catch {
                if (active) {
                    setProblem('We could not load the logs. We try again in a few seconds.');
                }
            }
        };

        load();
        const timer = range === 'custom' ? undefined : window.setInterval(load, REFRESH_MS);

        return () => {
            active = false;
            window.clearInterval(timer);
        };
    }, [environment.id, process, range, customFrom, customTo, search]);

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

    const pickRange = (value: string) => {
        if (value === 'custom' && customFrom === '') {
            const now = new Date();
            setCustomFrom(localInput(new Date(now.getTime() - 3600 * 1000)));
            setCustomTo(localInput(now));
        }

        setRange(value);
    };

    const submitSearch: FormEventHandler = (event) => {
        event.preventDefault();
        setSearch(searchText.trim());
    };

    const empty = search === '' ? 'There are no log lines in this range.' : 'No log line in this range has this text.';

    return (
        <EnvironmentLayout page={props} tab="logs" title="Logs">
            <div className="flex flex-wrap items-center gap-2">
                {processes.map((name) => (
                    <Button key={name} size="sm" variant={name === process ? 'default' : 'secondary'} onClick={() => setProcess(name)}>
                        {name}
                    </Button>
                ))}
                {range !== 'custom' && <span className="text-muted-foreground ml-auto text-xs">Refreshes every 5 seconds</span>}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="logs-range">Time range</Label>
                    <Select value={range} onValueChange={pickRange}>
                        <SelectTrigger id="logs-range" className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(RANGES).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {range === 'custom' && (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor="logs-from">From</Label>
                            <Input id="logs-from" type="datetime-local" value={customFrom} onChange={(event) => setCustomFrom(event.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="logs-to">To</Label>
                            <Input id="logs-to" type="datetime-local" value={customTo} onChange={(event) => setCustomTo(event.target.value)} />
                        </div>
                    </>
                )}
                <form onSubmit={submitSearch} className="flex items-end gap-2">
                    <div className="grid gap-1">
                        <Label htmlFor="logs-search">Search</Label>
                        <Input
                            id="logs-search"
                            className="w-64"
                            maxLength={200}
                            placeholder="Text in the line"
                            value={searchText}
                            onChange={(event) => setSearchText(event.target.value)}
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>
            </div>

            {problem && <p className="text-sm text-red-600 dark:text-red-400">{problem}</p>}
            {result?.truncated && (
                <p className="text-muted-foreground text-sm">
                    This shows only the newest lines of the range. To see older lines, pick a shorter range.
                </p>
            )}

            <pre
                ref={box}
                onScroll={onScroll}
                aria-label={`Logs of the ${process} process`}
                className="h-[32rem] overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-neutral-100"
            >
                {result === null
                    ? problem === null && 'Loading the logs.'
                    : (result.error ??
                      (result.lines.length === 0
                          ? empty
                          : result.lines.map((line, index) => (
                                <div key={index}>
                                    <span className="text-neutral-500">{shortTime(line.time)} </span>
                                    <span className="text-sky-300">{line.pod} </span>
                                    {line.line}
                                </div>
                            ))))}
            </pre>
        </EnvironmentLayout>
    );
}
