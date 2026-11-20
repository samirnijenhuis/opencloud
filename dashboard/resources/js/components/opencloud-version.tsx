import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function OpencloudVersion() {
    const { opencloud } = usePage<SharedData>().props;

    if (!opencloud) {
        return null;
    }

    return (
        <div className="text-muted-foreground space-y-1 px-2 text-xs group-data-[collapsible=icon]:hidden">
            {opencloud.update && (
                <p className="text-foreground" role="status">
                    Opencloud {opencloud.update} is available.{' '}
                    <Link href={route('updates.edit')} className="underline underline-offset-2">
                        Update
                    </Link>
                </p>
            )}
            <p>Opencloud {opencloud.version}</p>
        </div>
    );
}
