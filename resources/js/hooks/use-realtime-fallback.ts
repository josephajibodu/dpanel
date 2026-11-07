import { router, usePoll } from '@inertiajs/react';
import { useConnectionStatus } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

const POLL_INTERVAL_MS = 5000;

/**
 * Keeps live props current while the realtime connection is down, for pages
 * where a missed update would leave someone looking at a stale status, such as
 * a server or site that is still provisioning.
 *
 * While enabled, it polls the given props every few seconds until the
 * connection is up, then reloads them once to catch anything broadcast before
 * the page was listening. Inertia does that catch-up after a reconnect, but not
 * after the first connect.
 *
 * The props are read once, since a page always refreshes the same ones.
 * Returns the connection status so the page can show whether it is live.
 */
export function useRealtimeFallback(only: string[], enabled: boolean) {
    const connectionStatus = useConnectionStatus();
    const isConnected = connectionStatus === 'connected';
    const props = useRef(only);
    const hasConnected = useRef(isConnected);
    const { start, stop } = usePoll(
        POLL_INTERVAL_MS,
        { only },
        { autoStart: false },
    );

    useEffect(() => {
        if (enabled && !isConnected) {
            start();

            return stop;
        }

        stop();
    }, [enabled, isConnected, start, stop]);

    useEffect(() => {
        if (!isConnected || hasConnected.current) {
            return;
        }

        hasConnected.current = true;

        if (enabled) {
            router.reload({ only: props.current });
        }
    }, [enabled, isConnected]);

    return connectionStatus;
}
