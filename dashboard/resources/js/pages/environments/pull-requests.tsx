import { DeploymentBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import EnvironmentLayout from '@/layouts/environment-layout';
import { type DeploymentStatus, type EnvironmentPageProps } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useState } from 'react';

type PullRequest = {
    number: number;
    title: string;
    author: string;
    head_ref: string;
    labels: string[];
    url: string;
    /** The preview environment of the pull request, or null when it has none. */
    preview: { id: number; name: string; status: DeploymentStatus | null } | null;
    /** Why the pull request cannot get a preview, or null when it can. */
    refusal: string | null;
};

/** pull_requests is null when the GitHub App cannot read the pull requests of the repository, or GitHub did not answer. */
type Props = EnvironmentPageProps & { repository: string; github_error: string | null; pull_requests: PullRequest[] | null };

/** A refused deploy comes back as the error "deploy". The button keeps that error itself, as the Deploy button of the header does. */
function DeployPreview({ environmentId, pullRequest }: { environmentId: number; pullRequest: PullRequest }) {
    const [deploying, setDeploying] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const deploy = () => {
        router.post(
            route('environments.pull-requests.deploy', { environment: environmentId, number: pullRequest.number }),
            {},
            {
                preserveScroll: true,
                preserveState: 'errors',
                onStart: () => {
                    setDeploying(true);
                    setError(null);
                },
                onError: (errors) => setError(errors.deploy ?? null),
                onFinish: () => setDeploying(false),
            },
        );
    };

    return (
        <div className="flex flex-col items-start gap-1 sm:items-end">
            <Button variant={pullRequest.preview ? 'secondary' : 'default'} size="sm" onClick={deploy} disabled={deploying}>
                <Rocket />
                {deploying ? 'Starting' : pullRequest.preview ? 'Deploy again' : 'Deploy preview'}
            </Button>
            {error && (
                <p role="alert" className="max-w-xs text-sm text-red-600 sm:text-right dark:text-red-400">
                    {error}
                </p>
            )}
        </div>
    );
}

export default function EnvironmentPullRequests(props: Props) {
    const { environment, can, repository, github_error: githubError, pull_requests: pullRequests } = props;

    return (
        <EnvironmentLayout page={props} tab="pull-requests" title="Pull requests">
            {githubError ? (
                <p className="text-muted-foreground text-sm">{githubError}</p>
            ) : pullRequests === null ? (
                <p className="text-muted-foreground text-sm">
                    The GitHub App is not installed on {repository}. Install it in{' '}
                    <Link href={route('github.index')} className="text-foreground font-medium underline">
                        Settings, GitHub
                    </Link>
                    .
                </p>
            ) : pullRequests.length === 0 ? (
                <p className="text-muted-foreground text-sm">{repository} has no open pull requests.</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {pullRequests.map((pullRequest) => (
                        <li key={pullRequest.number} className="grid grid-cols-1 items-center gap-3 p-4 sm:grid-cols-[1fr_auto] sm:gap-6">
                            <div className="min-w-0 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <a
                                        href={pullRequest.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="font-medium break-words hover:underline"
                                    >
                                        #{pullRequest.number} {pullRequest.title}
                                    </a>
                                    {pullRequest.labels.map((label) => (
                                        <Badge key={label} variant="outline">
                                            {label}
                                        </Badge>
                                    ))}
                                </div>
                                <p className="text-muted-foreground text-sm break-all">
                                    <span className="font-mono">{pullRequest.head_ref}</span> by {pullRequest.author}
                                </p>
                                {pullRequest.preview && (
                                    <p className="flex flex-wrap items-center gap-2 text-sm">
                                        <Link href={route('environments.show', pullRequest.preview.id)} className="font-medium hover:underline">
                                            {pullRequest.preview.name}
                                        </Link>
                                        <DeploymentBadge status={pullRequest.preview.status} />
                                    </p>
                                )}
                            </div>
                            {pullRequest.refusal ? (
                                <p className="text-muted-foreground max-w-xs text-sm sm:text-right">{pullRequest.refusal}</p>
                            ) : (
                                can.deploy && <DeployPreview environmentId={environment.id} pullRequest={pullRequest} />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </EnvironmentLayout>
    );
}
