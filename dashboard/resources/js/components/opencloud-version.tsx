import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export function OpencloudVersion() {
    const { opencloud } = usePage<SharedData>().props;

    if (!opencloud) {
        return null;
    }

    return (
        <div className="text-muted-foreground space-y-1 px-2 text-xs group-data-[collapsible=icon]:hidden">
            {opencloud.update && (
                <p className="text-foreground" role="status">
                    Opencloud {opencloud.update} is available. Run <code className="font-mono">sudo opencloud upgrade</code> on the server.
                </p>
            )}
            <p>Opencloud {opencloud.version}</p>
        </div>
    );
}
