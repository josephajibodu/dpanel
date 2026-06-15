import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for worker / cron-job lifecycle changes via WebSocket.
 * Subscribes to server.{serverId} and triggers a partial reload of
 * `workers` + `cronJobs` so both the server-level processes page and the
 * site-scoped processes page stay live.
 *
 * Event: .server.processes.updated
 */
export function useServerProcessesUpdates(serverId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][processes] Echo not available for process updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][processes] subscribing to server processes channel', { serverId });
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][processes] subscribed to server processes channel', { serverId });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][processes] processes channel error', { serverId, error });
        });

        const reloadProcesses = () => {
            if (debugEnabled) {
                console.debug('[realtime][processes] reloading workers + cronJobs');
            }
            router.reload({
                only: ['workers', 'cronJobs'],
                preserveScroll: true,
            });
        };

        channel.listen('.server.processes.updated', reloadProcesses);

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][processes] leaving server processes channel', { serverId });
                }
                channelRef.current.stopListening('.server.processes.updated');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, serverId]);
}
