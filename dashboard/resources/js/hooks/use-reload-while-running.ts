import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

/** Reloads the named props of the page while a deployment runs, and stops when none runs. */
export function useReloadWhileRunning(running: boolean, only: string[], interval = 2000) {
    const { start, stop } = usePoll(interval, { only }, { autoStart: false });

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [running, start, stop]);
}
