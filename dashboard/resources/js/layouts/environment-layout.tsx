import DeployButton from '@/components/deploy-button';
import { ColorDot } from '@/components/environment-color';
import { DeploymentBadge } from '@/components/status-badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type EnvironmentLink, type EnvironmentPageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, GitBranch } from 'lucide-react';

const tabs = [
    { key: 'overview', title: 'Overview', route: 'environments.show' },
    { key: 'deployments', title: 'Deployments', route: 'environments.deployments' },
    { key: 'logs', title: 'Logs', route: 'environments.logs' },
    { key: 'env', title: 'Environment values', route: 'environments.env' },
    { key: 'domains', title: 'Domains', route: 'environments.domains' },
    { key: 'datastores', title: 'Datastores', route: 'environments.datastores' },
    { key: 'settings', title: 'Settings', route: 'environments.settings' },
] as const;

export type EnvironmentTab = (typeof tabs)[number]['key'];

/** "https://github.com/acme/shop.git" and "acme/shop" both give "acme/shop". */
const repositoryName = (repository: string) => repository.replace(/^https?:\/\/[^/]+\//, '').replace(/\.git$/, '');

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
    children,
}: {
    page: EnvironmentPageProps;
    tab: EnvironmentTab | null;
    title: string;
    children: React.ReactNode;
}) {
    const { application, environment, can } = page;
    const errors = usePage().props.errors as Record<string, string>;
    const routeName = tabs.find((item) => item.key === tab)?.route ?? 'environments.show';

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
                                <DeploymentBadge status={environment.status} />
                            </h1>
                            <p className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-0.5 text-sm">
                                <span>{application.type === 'laravel' ? 'Laravel' : 'Dockerfile'}</span>
                                <span className="inline-flex min-w-0 items-center gap-1">
                                    <GitBranch className="size-3.5 shrink-0" />
                                    <span className="break-all">
                                        {repositoryName(application.repository)}
                                        {environment.branch && `:${environment.branch}`}
                                    </span>
                                </span>
                                {environment.kind !== 'preview_template' && (
                                    <span>{environment.auto_deploy ? 'Deploys on push' : 'Deploys by hand'}</span>
                                )}
                            </p>
                        </div>
                    </div>

                    {can.deploy && environment.kind !== 'preview_template' && <DeployButton environmentId={environment.id} />}
                </div>

                {errors.deploy && <p className="text-sm text-red-600 dark:text-red-400">{errors.deploy}</p>}

                <div className="space-y-6">{children}</div>
            </div>
        </AppLayout>
    );
}
