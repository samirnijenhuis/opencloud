import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, Link } from '@inertiajs/react';

type Event = {
    id: number;
    user: string | null;
    action: string;
    subject: string | null;
    created_at: string;
};

type Page = {
    data: Event[];
    prev_page_url: string | null;
    next_page_url: string | null;
    current_page: number;
    last_page: number;
};

export default function Audit({ events }: { events: Page }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Audit log', href: '/settings/audit' }]}>
            <Head title="Audit log" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Audit log" description="Who changed what, newest first" />

                    {events.data.length === 0 ? (
                        <p className="text-sm">Nothing has happened yet.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {events.data.map((event) => (
                                <li key={event.id} className="space-y-0.5 p-4 text-sm">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-mono">{event.action}</span>
                                        <time dateTime={event.created_at} className="text-muted-foreground text-xs">
                                            {new Date(event.created_at).toLocaleString()}
                                        </time>
                                    </div>
                                    <p className="text-muted-foreground">
                                        {event.user ?? 'The system'}
                                        {event.subject && <> - {event.subject}</>}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground text-sm">
                            Page {events.current_page} of {events.last_page}
                        </span>
                        <div className="flex gap-2">
                            {events.prev_page_url ? (
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={events.prev_page_url}>Newer</Link>
                                </Button>
                            ) : (
                                <Button variant="secondary" size="sm" disabled>
                                    Newer
                                </Button>
                            )}
                            {events.next_page_url ? (
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={events.next_page_url}>Older</Link>
                                </Button>
                            ) : (
                                <Button variant="secondary" size="sm" disabled>
                                    Older
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
