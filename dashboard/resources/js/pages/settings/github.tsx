import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Installation = {
    account: string;
    repository_selection: 'all' | 'selected';
};

type ConnectedApp = {
    app_id: number;
    slug: string;
    owner: string;
    html_url: string;
    install_url: string;
    installations: Installation[] | null;
    error: string | null;
};

type Props = {
    app: ConnectedApp | null;
    manifest: string;
    state: string;
    status: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'GitHub',
        href: '/settings/github',
    },
];

function manifestAction(organization: string, state: string): string {
    const owner = organization.trim();
    const path = owner === '' ? 'settings/apps/new' : `organizations/${encodeURIComponent(owner)}/settings/apps/new`;

    return `https://github.com/${path}?state=${encodeURIComponent(state)}`;
}

function AppState({ app }: { app: ConnectedApp }) {
    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border p-4 text-sm">
                <p>
                    The GitHub App{' '}
                    <a href={app.html_url} target="_blank" rel="noopener noreferrer" className="font-medium hover:underline">
                        {app.slug}
                    </a>{' '}
                    belongs to {app.owner}. Its app ID is {app.app_id}.
                </p>
                {app.error && <p className="text-red-600 dark:text-red-400">{app.error}</p>}
                {app.installations?.length === 0 && <p className="text-muted-foreground">The app is not installed on an account yet.</p>}
            </div>

            {app.installations && app.installations.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {app.installations.map((installation) => (
                        <li key={installation.account} className="flex items-center justify-between gap-4 p-4 text-sm">
                            <span className="font-medium">{installation.account}</span>
                            <span className="text-muted-foreground">
                                {installation.repository_selection === 'all' ? 'All repositories' : 'Selected repositories'}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <Button asChild>
                <a href={app.install_url} target="_blank" rel="noopener noreferrer">
                    Install the app on GitHub
                </a>
            </Button>
        </div>
    );
}

export default function GitHubSettings({ app, manifest, state, status }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [organization, setOrganization] = useState('');
    const hookUrl = (JSON.parse(manifest) as { hook_attributes: { url: string } }).hook_attributes.url;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="GitHub" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="GitHub"
                        description="A GitHub App lets opencloud read private repositories, deploy on push, and post preview links on pull requests"
                    />

                    {status && <p className="text-sm text-green-700 dark:text-green-400">{status}</p>}
                    <InputError message={errors.github} />

                    {app ? <AppState app={app} /> : <p className="text-muted-foreground text-sm">Opencloud has no GitHub App yet.</p>}
                </div>

                <div className="space-y-6">
                    <HeadingSmall
                        title={app ? 'Create a new GitHub App' : 'Create the GitHub App'}
                        description={
                            app
                                ? 'The new app replaces the current one. Install the new app on your repositories after you create it.'
                                : 'GitHub creates the app with the right permissions and sends you back here'
                        }
                    />

                    <form method="post" action={manifestAction(organization, state)} className="space-y-4">
                        <input type="hidden" name="manifest" value={manifest} />

                        <div className="grid gap-2">
                            <Label htmlFor="organization">Organization</Label>
                            <Input
                                id="organization"
                                className="max-w-xs"
                                value={organization}
                                onChange={(e) => setOrganization(e.target.value)}
                                placeholder="Leave empty for your personal account"
                            />
                        </div>

                        <p className="text-muted-foreground text-sm">
                            GitHub sends push and pull request events to {hookUrl}. GitHub must be able to reach this address.
                        </p>

                        <Button type="submit">Create the GitHub App</Button>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
