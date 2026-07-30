import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for server list updates via WebSocket.
 * Subscribes to servers.{teamId} and triggers partial reload on status changes or deletion.
 *
 * Events: .server.status.changed, .server.deleted
 */
export function useServersListUpdates(teamId: number) {
    useEcho(`servers.${teamId}`, ['.server.status.changed', '.server.deleted'], () => {
        router.reload({
            only: ['servers'],
            preserveScroll: true,
        });
    });
}
