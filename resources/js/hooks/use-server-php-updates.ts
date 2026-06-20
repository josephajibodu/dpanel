import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for PHP service updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .server.php.updated
 */
export function useServerPhpUpdates(serverId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][php] Echo not available for PHP updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][php] subscribing to server PHP channel', { serverId });
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][php] subscribed to server PHP channel', { serverId });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][php] channel error', { serverId, error });
        });

        const reloadPhp = () => {
            if (debugEnabled) {
                console.debug('[realtime][php] reloading PHP props');
            }
            router.reload({
                only: [
                    'phpServices',
                    'installedVersions',
                    'defaultVersion',
                    'settings',
                    'settingsSyncStatus',
                    'settingsSyncError',
                ],
                preserveScroll: true,
            });
        };

        channel.listen('.server.php.updated', reloadPhp);

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][php] leaving server PHP channel', { serverId });
                }
                channelRef.current.stopListening('.server.php.updated');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, serverId]);
}
