import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Reloads the named props of the page every 2 seconds while a deployment runs. With an idle interval, it also reloads
 * while none runs, because a push, a deploy hook, or another tab can start a deployment that the page does not know yet.
 */
export function useDeploymentPoll(running: boolean, only: string[], idleInterval: number | null = null) {
    const { start: startFast, stop: stopFast } = usePoll(2000, { only }, { autoStart: false });
    const { start: startIdle, stop: stopIdle } = usePoll(idleInterval ?? 10_000, { only }, { autoStart: false });

    // The start and stop functions keep their identity, so a re-render of the page does not reset the timers.
    useEffect(() => {
        if (running) {
            stopIdle();
            startFast();
        } else {
            stopFast();

            if (idleInterval !== null) {
                startIdle();
            }
        }

        return () => {
            stopFast();
            stopIdle();
        };
    }, [running, idleInterval, startFast, stopFast, startIdle, stopIdle]);
}
