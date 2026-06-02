import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for server list updates via WebSocket.
 * Subscribes to servers.{teamId} and triggers partial reload on status changes or deletion.
 *
 * Events: .server.status.changed, .server.deleted
 */
export function useServersListUpdates(teamId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!teamId || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][servers] Echo not available for list updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][servers] subscribing to servers list channel', { teamId });
        }

        const channel = window.Echo.private(`servers.${teamId}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][servers] subscribed to servers list channel', { teamId });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][servers] list channel error', { teamId, error });
        });

        const reloadServers = () => {
            if (debugEnabled) {
                console.debug('[realtime][servers] reloading servers list');
            }
            router.reload({
                only: ['servers'],
                preserveScroll: true,
            });
        };

        channel.listen('.server.status.changed', reloadServers);
        channel.listen('.server.deleted', reloadServers);

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][servers] leaving servers list channel', { teamId });
                }
                channelRef.current.stopListening('.server.status.changed');
                channelRef.current.stopListening('.server.deleted');
                window.Echo.leave(`servers.${teamId}`);
            }
        };
    }, [debugEnabled, teamId]);
}
