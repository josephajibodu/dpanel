import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

interface DeploymentStatusEvent {
    deployment_id?: number;
    site_id?: number;
}

/**
 * Hook to listen for deployment status changes via WebSocket.
 * Subscribes to server.{serverId} and triggers partial reload when deployment status changes.
 *
 * Events: .deployment.status.changed
 *
 * @param serverId - Server ID to subscribe to
 * @param siteId - Optional. If provided, only reload when the deployment is for this site
 * @param reloadOnly - Page props to reload (default: ['site', 'deployments'])
 */
export function useServerDeploymentUpdates(serverId: number, siteId?: number, reloadOnly: string[] = ['site', 'deployments']) {
    useEcho<DeploymentStatusEvent>(
        `server.${serverId}`,
        '.deployment.status.changed',
        (event) => {
            if (siteId != null && event.site_id != null && event.site_id !== siteId) {
                return;
            }

            router.reload({
                only: reloadOnly,
                preserveScroll: true,
            });
        },
        [siteId, reloadOnly],
    );
}
