import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for server connection updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .server.connections.updated
 */
export function useServerConnectionsUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.server.connections.updated', () => {
        router.reload({
            only: ['connections', 'availableServers', 'redisPassword'],
        });
    });
}
