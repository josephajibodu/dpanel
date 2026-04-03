import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for site provisioning updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload when site status/step changes.
 *
 * Events: .server.sites.updated
 *
 * @param serverId - Server ID to subscribe to
 * @param siteId - Site ID (used to determine if we should reload)
 * @param enabled - Only subscribe when site is installing or pending
 */
export function useSiteProvisioningUpdates(
    serverId: number,
    siteId?: number,
    enabled = true,
) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !siteId || !enabled || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][site-provisioning] Echo not available');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][site-provisioning] subscribing to server channel', {
                serverId,
                siteId,
            });
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.listen('.server.sites.updated', () => {
            if (debugEnabled) {
                console.debug('[realtime][site-provisioning] server sites updated, reloading site');
            }

            router.reload({
                only: ['site'],
                preserveScroll: true,
            });
        });

        return () => {
            if (channelRef.current) {
                channelRef.current.stopListening('.server.sites.updated');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, enabled, serverId, siteId]);
}
