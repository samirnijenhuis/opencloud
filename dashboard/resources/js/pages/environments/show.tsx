import { DeploymentBadge } from '@/components/status-badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import EnvironmentLayout from '@/layouts/environment-layout';
import { duration, shortSha, timeAgo } from '@/lib/format';
import { type Deployment, type EnvironmentPageProps } from '@/types';
import { Link, router } from '@inertiajs/react';

type Props = EnvironmentPageProps & { latestDeployment: Deployment | null };

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <dt className="text-muted-foreground text-xs tracking-wide uppercase">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

export default function EnvironmentShow(props: Props) {
    const { environment, can, latestDeployment } = props;

    const setAutoDeploy = (checked: boolean) => {
        router.patch(route('environments.update', environment.id), { auto_deploy: checked }, { preserveScroll: true });
    };

    return (
        <EnvironmentLayout page={props} tab="overview" title="Overview">
            <dl className="grid gap-6 sm:grid-cols-3">
                <Fact label="Live commit">
                    {environment.live_commit ? <code className="font-mono">{shortSha(environment.live_commit)}</code> : 'Nothing is live yet'}
                </Fact>
                <Fact label="Latest deployment">
                    {latestDeployment ? (
                        <span className="flex items-center gap-2">
                            <DeploymentBadge status={latestDeployment.status} />
                            <Link href={route('deployments.show', latestDeployment.id)} className="hover:underline">
                                {timeAgo(latestDeployment.created_at)}
                            </Link>
                        </span>
                    ) : (
                        'No deployments yet'
                    )}
                </Fact>
                <Fact label="Last build time">{latestDeployment ? duration(latestDeployment.started_at, latestDeployment.finished_at) : '-'}</Fact>
            </dl>

            <div className="space-y-2">
                <h2 className="text-sm font-medium">Addresses</h2>
                {environment.urls.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        This environment has no domain yet.{' '}
                        <Link href={route('environments.domains', environment.id)} className="underline">
                            Add a domain
                        </Link>
                    </p>
                ) : (
                    <ul className="space-y-1 text-sm">
                        {environment.urls.map((url) => (
                            <li key={url}>
                                <a href={url} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline dark:text-blue-400">
                                    {url.replace('https://', '')}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="space-y-2 rounded-lg border p-4">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={environment.auto_deploy}
                        disabled={!can.update}
                        onCheckedChange={(checked) => setAutoDeploy(checked === true)}
                    />
                    Deploy automatically when the {environment.branch} branch changes
                </Label>
                {!can.update && (
                    <p className="text-muted-foreground text-xs">Only an admin or an owner can change this for a protected environment.</p>
                )}
            </div>
        </EnvironmentLayout>
    );
}
