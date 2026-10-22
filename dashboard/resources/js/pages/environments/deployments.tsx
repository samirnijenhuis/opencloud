import { DeploymentBadge, isRunning } from '@/components/status-badge';
import EnvironmentLayout from '@/layouts/environment-layout';
import { duration, shortSha, timeAgo } from '@/lib/format';
import { type Deployment, type EnvironmentPageProps } from '@/types';
import { Link } from '@inertiajs/react';

export default function EnvironmentDeployments(props: EnvironmentPageProps & { deployments: Deployment[] }) {
    const { deployments } = props;

    return (
        <EnvironmentLayout page={props} tab="deployments" title="Deployments">
            {deployments.length === 0 ? (
                <p className="text-muted-foreground text-sm">No deployments yet. Select Deploy to start the first one.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground border-b text-left">
                            <tr>
                                <th className="p-3 font-medium">Status</th>
                                <th className="p-3 font-medium">Commit</th>
                                <th className="p-3 font-medium">Started by</th>
                                <th className="p-3 font-medium">Duration</th>
                                <th className="p-3 font-medium">When</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {deployments.map((deployment) => (
                                <tr key={deployment.id}>
                                    <td className="p-3">
                                        <DeploymentBadge status={deployment.status} />
                                    </td>
                                    <td className="p-3">
                                        <Link href={route('deployments.show', deployment.id)} className="font-mono hover:underline">
                                            {shortSha(deployment.commit_sha)}
                                        </Link>
                                    </td>
                                    <td className="p-3">{deployment.triggered_by ?? 'Automatic'}</td>
                                    <td className="p-3">
                                        {isRunning(deployment.status) ? 'Running' : duration(deployment.started_at, deployment.finished_at)}
                                    </td>
                                    <td className="text-muted-foreground p-3">{timeAgo(deployment.created_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </EnvironmentLayout>
    );
}
