import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for database and database user updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .server.databases.updated
 */
export function useServerDatabasesUpdates(serverId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][databases] Echo not available for database updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][databases] subscribing to server databases channel', { serverId });
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][databases] subscribed to server databases channel', { serverId });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][databases] channel error', { serverId, error });
        });

        const reloadDatabases = () => {
            if (debugEnabled) {
                console.debug('[realtime][databases] reloading databases and users');
            }
            router.reload({
                only: ['databases', 'databaseUsers'],
                preserveScroll: true,
            });
        };

        channel.listen('.server.databases.updated', reloadDatabases);

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][databases] leaving server databases channel', { serverId });
                }
                channelRef.current.stopListening('.server.databases.updated');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, serverId]);
}
