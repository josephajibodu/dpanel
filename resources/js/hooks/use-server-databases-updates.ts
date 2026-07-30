import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for database and database user updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .server.databases.updated
 */
export function useServerDatabasesUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.server.databases.updated', () => {
        router.reload({
            only: ['databases', 'databaseUsers'],
            preserveScroll: true,
        });
    });
}
