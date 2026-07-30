import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for site provisioning updates via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload when site status/step changes.
 *
 * Events: .server.sites.updated
 *
 * @param serverId - Server ID to subscribe to
 * @param siteId - Site ID (used to determine if we should reload)
 * @param enabled - Only subscribe when site is installing or pending
 */
export function useSiteProvisioningUpdates(serverId: number, siteId?: number, enabled = true) {
    useEcho(
        enabled && siteId ? `server.${serverId}` : '',
        '.server.sites.updated',
        () => {
            router.reload({
                only: ['site'],
                preserveScroll: true,
            });
        },
        [],
    );
}
