import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, useForm } from '@inertiajs/react';

type Upgrade = { state: 'starting' | 'running' | 'done' | 'failed'; from: string; target: string; message: string };
type Props = { version: string; latest: string | null; lastUpgrade: Upgrade | null };

function LastUpgrade({ upgrade }: { upgrade: Upgrade }) {
    if (upgrade.state === 'failed') {
        return (
            <div className="space-y-1 rounded-md border p-4 text-sm">
                <p className="font-medium text-red-700 dark:text-red-400">The update to {upgrade.target} failed.</p>
                <p className="text-muted-foreground">{upgrade.message}</p>
            </div>
        );
    }

    if (upgrade.state === 'starting' || upgrade.state === 'running') {
        return (
            <p className="text-sm">
                An update to {upgrade.target} runs now.{' '}
                <a href={route('updates.progress')} className="underline underline-offset-2">
                    Show the progress
                </a>
            </p>
        );
    }

    return null;
}

export default function Updates({ version, latest, lastUpgrade }: Props) {
    const form = useForm({ target: latest ?? '' });

    const update = () => {
        if (confirm(`Update opencloud to ${latest}? The dashboard restarts and is offline for about a minute. Your apps keep running.`)) {
            form.post(route('updates.store'));
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Updates', href: route('updates.edit') }]}>
            <Head title="Updates" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Updates" description="Update opencloud to its latest release." />

                    <p className="text-sm">
                        Opencloud <strong className="font-mono">{version}</strong> runs now.
                    </p>

                    {lastUpgrade && <LastUpgrade upgrade={lastUpgrade} />}

                    {lastUpgrade?.state === 'running' ? null : latest ? (
                        <div className="space-y-2">
                            <p className="text-sm">
                                Opencloud <strong className="font-mono">{latest}</strong> is available. The dashboard restarts during the update. Your
                                apps keep running.
                            </p>
                            <Button onClick={update} disabled={form.processing}>
                                Update to {latest}
                            </Button>
                            <InputError message={form.errors.target} />
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">Opencloud checks for a new release once a day. No newer release is available.</p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
