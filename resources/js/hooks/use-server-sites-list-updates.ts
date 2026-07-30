import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for sites list updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload when sites change.
 *
 * Events: .server.sites.updated
 */
export function useServerSitesListUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.server.sites.updated', () => {
        router.reload({
            only: ['sites'],
            preserveScroll: true,
        });
    });
}
