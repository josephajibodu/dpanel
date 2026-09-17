import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for site provisioning updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload when site status/step changes.
 *
 * Events: .server.sites.updated
 *
 * Falls back to polling every 5s while enabled and the websocket connection
 * isn't up yet, so a fast provisioning failure isn't missed if the Echo
 * subscription hadn't finished connecting when it happened.
 *
 * @param serverId - Server ID to subscribe to
 * @param siteId - Site ID (used to determine if we should reload)
 * @param enabled - Only subscribe when site is installing or pending
 */
export function useSiteProvisioningUpdates(serverId: number, siteId?: number, enabled = true) {
    const connectionState = useConnectionStatus();
    const pollingIntervalRef = useRef<number | null>(null);

    useEcho(
        enabled && siteId ? `server.${serverId}` : '',
        '.server.sites.updated',
        () => {
            router.reload({
                only: ['site'],
                preserveScroll: true,
            });
        },
        [],
    );

    useEffect(() => {
        const shouldPollFallback = enabled && !!siteId && connectionState !== 'connected';

        if (!shouldPollFallback) {
            if (pollingIntervalRef.current) {
                window.clearInterval(pollingIntervalRef.current);
                pollingIntervalRef.current = null;
            }
            return;
        }

        pollingIntervalRef.current = window.setInterval(() => {
            router.reload({
                only: ['site'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 5000);

        return () => {
            if (pollingIntervalRef.current) {
                window.clearInterval(pollingIntervalRef.current);
                pollingIntervalRef.current = null;
            }
        };
    }, [enabled, siteId, connectionState]);
}
