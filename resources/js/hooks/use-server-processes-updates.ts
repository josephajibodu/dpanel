import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for worker / cron-job lifecycle changes via WebSocket.
 * Subscribes to server.{serverId} and triggers a partial reload of
 * `workers` + `cronJobs` so both the server-level processes page and the
 * site-scoped processes page stay live.
 *
 * Event: .server.processes.updated
 */
export function useServerProcessesUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.server.processes.updated', () => {
        router.reload({
            only: ['workers', 'cronJobs'],
            preserveScroll: true,
        });
    });
}
