import { DeploymentBadge, isRunning } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import EnvironmentLayout from '@/layouts/environment-layout';
import { duration, shortSha } from '@/lib/format';
import { type Deployment, type EnvironmentPageProps } from '@/types';
import { Link, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

type Props = EnvironmentPageProps & { deployment: Deployment & { log: string } };

export default function DeploymentShow(props: Props) {
    const { deployment, environment } = props;
    const running = isRunning(deployment.status);
    const { start, stop } = usePoll(2000, { only: ['deployment', 'environment'] }, { autoStart: false });
    const box = useRef<HTMLPreElement>(null);
    const following = useRef(true);

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [running, start, stop]);

    useEffect(() => {
        const element = box.current;

        if (element && following.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [deployment.log]);

    const onScroll = () => {
        const element = box.current;

        if (element) {
            following.current = element.scrollHeight - element.scrollTop - element.clientHeight < 40;
        }
    };

    return (
        <EnvironmentLayout page={props} tab="deployments" title={`Deployment ${shortSha(deployment.commit_sha)}`}>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <DeploymentBadge status={deployment.status} />
                <span>
                    Commit <code className="font-mono">{shortSha(deployment.commit_sha)}</code>
                </span>
                <span>Started by {deployment.triggered_by ?? 'an automatic trigger'}</span>
                <span>{running ? 'Running' : duration(deployment.started_at, deployment.finished_at)}</span>
                <Link href={route('environments.deployments', environment.id)} className="text-muted-foreground ml-auto hover:underline">
                    All deployments
                </Link>
            </div>

            {deployment.warning && (
                <Alert>
                    <AlertDescription>{deployment.warning}</AlertDescription>
                </Alert>
            )}

            <pre
                ref={box}
                onScroll={onScroll}
                aria-label="Deployment log"
                className="h-[28rem] overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-neutral-100"
            >
                {deployment.log || (running ? 'Waiting for the first line of the log.' : 'This deployment wrote no log.')}
            </pre>
            {running && <p className="text-muted-foreground text-xs">This page updates every 2 seconds.</p>}
        </EnvironmentLayout>
    );
}
