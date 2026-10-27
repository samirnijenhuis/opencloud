import { DeploymentSteps } from '@/components/deployment-steps';
import { DeploymentBadge, isRunning } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useDeploymentPoll } from '@/hooks/use-deployment-poll';
import EnvironmentLayout from '@/layouts/environment-layout';
import { duration, shortSha } from '@/lib/format';
import { type Deployment, type DeploymentStep, type EnvironmentPageProps } from '@/types';
import { Link } from '@inertiajs/react';

type WaitingFor = { id: number; application: string; environment: string; queued_before: number };

type Props = EnvironmentPageProps & { deployment: Deployment; steps: DeploymentStep[]; waiting_for: WaitingFor | null };

export default function DeploymentShow(props: Props) {
    const { deployment, steps, environment, waiting_for } = props;
    const running = isRunning(deployment.status);
    useDeploymentPoll(running, ['deployment', 'steps', 'environment', 'waiting_for']);

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

            {waiting_for && (
                <Alert>
                    <AlertDescription>
                        Opencloud builds one deployment at a time. This deployment starts after{' '}
                        <Link href={route('deployments.show', waiting_for.id)} className="underline">
                            deployment {waiting_for.id} of {waiting_for.application} / {waiting_for.environment}
                        </Link>
                        {waiting_for.queued_before > 0
                            ? ` and ${waiting_for.queued_before} other queued ${waiting_for.queued_before === 1 ? 'deployment' : 'deployments'}.`
                            : '.'}
                    </AlertDescription>
                </Alert>
            )}

            {deployment.warning && (
                <Alert>
                    <AlertDescription>{deployment.warning}</AlertDescription>
                </Alert>
            )}

            <DeploymentSteps steps={steps} running={running} />
            {running && <p className="text-muted-foreground text-xs">This page updates every 2 seconds.</p>}
        </EnvironmentLayout>
    );
}
