import DeployButton from '@/components/deploy-button';
import { ColorDot } from '@/components/environment-color';
import { Status } from '@/components/environment/block';
import { RepositoryAccessContext, RepositorySheet } from '@/components/environment/repository-sheet';
import { DeploymentBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePoll } from '@/hooks/use-poll';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { applicationTypeLabels, type EnvironmentLink, type EnvironmentPageProps } from '@/types';
import { type EnvironmentRepositoryAccess, type PushDelivery } from '@/types/repository-access';
import { Head, Link } from '@inertiajs/react';
import { Check, ChevronsUpDown, GitBranch } from 'lucide-react';
import { useState } from 'react';

const tabs = [
    { key: 'environment', title: 'Environment', route: 'environments.show' },
    { key: 'deployments', title: 'Deployments', route: 'environments.deployments' },
    { key: 'commands', title: 'Commands', route: 'environments.commands' },
    { key: 'logs', title: 'Logs', route: 'environments.logs' },
    { key: 'settings', title: 'Settings', route: 'environments.settings' },
] as const;

export type EnvironmentTab = (typeof tabs)[number]['key'];

/** "https://github.com/acme/shop.git" and "acme/shop" both give "acme/shop". */
const repositoryName = (repository: string) => repository.replace(/^https?:\/\/[^/]+\//, '').replace(/\.git$/, '');

const pushTexts: Record<PushDelivery, string> = {
    github_app: 'Deploys on push',
    deploy_hook: 'Deploys through the deploy hook',
    not_connected: 'Push deploys not connected',
    off: 'Deploys by hand',
};

function SwitcherItem({ item, current, routeName }: { item: EnvironmentLink; current: number; routeName: string }) {
    return (
        <DropdownMenuItem asChild>
            <Link href={route(routeName, item.id)} className="cursor-pointer">
                <ColorDot color={item.color} />
                <span className="truncate">{item.name}</span>
                {item.id === current && <Check className="ml-auto" />}
            </Link>
        </DropdownMenuItem>
    );
}

/** Switching keeps the open tab, so a user can compare the settings of two environments. */
function EnvironmentSwitcher({ page, routeName }: { page: EnvironmentPageProps; routeName: string }) {
    const { application, environment } = page;
    const permanent = application.environments.filter((item) => item.kind !== 'preview');
    const previews = application.environments.filter((item) => item.kind === 'preview');

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="text-foreground hover:bg-accent focus-visible:ring-ring -mx-1.5 flex items-center gap-1.5 rounded-md px-1.5 py-1 outline-none focus-visible:ring-2">
                <ColorDot color={environment.color} />
                {environment.name}
                <ChevronsUpDown className="text-muted-foreground size-3.5" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-60">
                <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">Environments</DropdownMenuLabel>
                {permanent.map((item) => (
                    <SwitcherItem key={item.id} item={item} current={environment.id} routeName={routeName} />
                ))}
                {previews.length > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">Previews</DropdownMenuLabel>
                        {previews.map((item) => (
                            <SwitcherItem key={item.id} item={item} current={environment.id} routeName={routeName} />
                        ))}
                    </>
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={route('applications.index')} className="cursor-pointer">
                        All applications
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function EnvironmentLayout({
    page,
    tab,
    title,
    actions,
    children,
}: {
    page: EnvironmentPageProps;
    tab: EnvironmentTab | null;
    title: string;
    actions?: React.ReactNode;
    children: React.ReactNode;
}) {
    const { application, environment, can } = page;
    const routeName = tabs.find((item) => item.key === tab)?.route ?? 'environments.show';
    const [access, refresh] = usePoll<EnvironmentRepositoryAccess>(
        route('environments.repository-access', environment.id),
        10_000,
        (data) => !data.read_works,
    );
    const [repositoryOpen, setRepositoryOpen] = useState(false);

    return (
        <AppLayout
            breadcrumbs={[
                { title: application.name, href: route('applications.show', application.id) },
                {
                    title: environment.name,
                    href: route('environments.show', environment.id),
                    node: <EnvironmentSwitcher page={page} routeName={routeName} />,
                },
            ]}
        >
            <Head title={`${title} - ${application.name} ${environment.name}`} />

            <div className="border-b">
                <nav
                    className="mx-auto flex max-w-6xl gap-6 overflow-x-auto overflow-y-hidden px-4 [scrollbar-width:none]"
                    aria-label="Environment sections"
                >
                    {tabs.map((item) => (
                        <Link
                            key={item.key}
                            href={route(item.route, environment.id)}
                            className={cn(
                                '-mb-px border-b-2 py-2.5 text-sm whitespace-nowrap',
                                item.key === tab
                                    ? 'border-foreground text-foreground'
                                    : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            {item.title}
                        </Link>
                    ))}
                </nav>
            </div>

            <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <div className="bg-muted grid size-10 shrink-0 place-items-center rounded-lg border font-medium" aria-hidden>
                            {application.name.charAt(0).toUpperCase()}
                        </div>
                        <div className="min-w-0 space-y-0.5">
                            <h1 className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xl font-semibold tracking-tight">
                                <span className="break-all">{application.name}</span>
                                <span className="text-muted-foreground flex items-center gap-2 font-normal">
                                    / <ColorDot color={environment.color} /> <span className="break-all">{environment.name}</span>
                                </span>
                                {environment.kind !== 'preview_template' && <DeploymentBadge status={environment.status} />}
                            </h1>
                            <p className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-0.5 text-sm">
                                <span>{applicationTypeLabels[application.type]}</span>
                                <button
                                    type="button"
                                    onClick={() => setRepositoryOpen(true)}
                                    className="hover:text-foreground focus-visible:ring-ring inline-flex min-w-0 items-center gap-1 rounded-sm text-left outline-none focus-visible:ring-2"
                                >
                                    <GitBranch className="size-3.5 shrink-0" />
                                    <span className="break-all">
                                        {repositoryName(application.repository)}
                                        {environment.branch && `:${environment.branch}`}
                                    </span>
                                    <Status tone={access === null ? 'off' : access.read_works ? 'ok' : 'bad'}>
                                        <span className="sr-only">{access === null ? 'Checking the repository' : access.summary}</span>
                                    </Status>
                                </button>
                                {access?.push &&
                                    (access.push === 'not_connected' ? (
                                        <Status tone="warn">{pushTexts[access.push]}</Status>
                                    ) : (
                                        <span>{pushTexts[access.push]}</span>
                                    ))}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-start gap-2">
                        {actions}
                        {can.deploy && environment.kind !== 'preview_template' && <DeployButton environmentId={environment.id} />}
                    </div>
                </div>

                {access && !access.read_works && (
                    <Alert variant="destructive" className="flex flex-wrap items-center justify-between gap-3 py-3">
                        <AlertDescription>{access.summary}</AlertDescription>
                        <Button variant="secondary" size="sm" onClick={() => setRepositoryOpen(true)}>
                            Show the steps
                        </Button>
                    </Alert>
                )}

                <RepositoryAccessContext.Provider value={{ access, refresh }}>
                    <div className="space-y-6">{children}</div>
                </RepositoryAccessContext.Provider>
            </div>

            <RepositorySheet
                open={repositoryOpen}
                onClose={() => setRepositoryOpen(false)}
                access={access}
                refresh={refresh}
                applicationId={application.id}
                environmentId={environment.id}
            />
        </AppLayout>
    );
}
