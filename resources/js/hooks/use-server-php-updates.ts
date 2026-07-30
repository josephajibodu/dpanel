import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for PHP service updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .server.php.updated
 */
export function useServerPhpUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.server.php.updated', () => {
        router.reload({
            only: ['phpServices', 'installedVersions', 'defaultVersion', 'settings', 'settingsSyncStatus', 'settingsSyncError'],
            preserveScroll: true,
        });
    });
}
