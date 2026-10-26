import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for runtime (Node.js) updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload on changes.
 *
 * Events: .server.runtime.updated
 */
export function useServerRuntimeUpdates(serverId: number) {
    useEcho(`server.${serverId}`, '.server.runtime.updated', () => {
        router.reload({ only: ['node'] });
    });
}
