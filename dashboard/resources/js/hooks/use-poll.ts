import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Reads JSON from the URL now and again every interval, but only while the tab is visible.
 * A null URL reads nothing. The poll stops when `keepPolling` returns false, and `refresh` reads once more.
 */
export function usePoll<T>(url: string | null, intervalMs: number, keepPolling: (data: T) => boolean = () => true): [T | null, () => void] {
    const [result, setResult] = useState<{ url: string; data: T } | null>(null);
    const [round, setRound] = useState(0);
    const keep = useRef(keepPolling);
    keep.current = keepPolling;

    useEffect(() => {
        if (url === null) {
            return;
        }

        let timer: number | undefined;
        let latest = 0;

        const load = async () => {
            const call = ++latest;
            window.clearTimeout(timer);

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const data = (await response.json()) as T;

                if (call !== latest) {
                    return;
                }

                setResult({ url, data });

                if (!keep.current(data)) {
                    return;
                }
            } catch {
                if (call !== latest) {
                    return;
                }
            }

            if (!document.hidden) {
                timer = window.setTimeout(load, intervalMs);
            }
        };

        const onVisibility = () => (document.hidden ? window.clearTimeout(timer) : load());

        load();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            latest = -1;
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [url, intervalMs, round]);

    const refresh = useCallback(() => setRound((value) => value + 1), []);

    return [result !== null && result.url === url ? result.data : null, refresh];
}
