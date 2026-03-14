import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for sites list updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload when sites change.
 *
 * Events: .server.sites.updated
 */
export function useServerSitesListUpdates(serverId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][sites] Echo not available for list updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][sites] subscribing to server sites channel', { serverId });
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][sites] subscribed to server sites channel', { serverId });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][sites] list channel error', { serverId, error });
        });

        const reloadSites = () => {
            if (debugEnabled) {
                console.debug('[realtime][sites] reloading sites list');
            }
            router.reload({
                only: ['sites'],
                preserveScroll: true,
            });
        };

        channel.listen('.server.sites.updated', reloadSites);

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][sites] leaving server sites channel', { serverId });
                }
                channelRef.current.stopListening('.server.sites.updated');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, serverId]);
}
