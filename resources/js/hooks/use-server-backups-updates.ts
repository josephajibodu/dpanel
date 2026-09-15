import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for database backup status changes via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .backup.status.changed
 */
export function useServerBackupsUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.backup.status.changed', () => {
        router.reload({
            only: ['schedule', 'backups'],
            preserveScroll: true,
        });
    });
}
