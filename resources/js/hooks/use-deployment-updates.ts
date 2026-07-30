import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

/**
 * Hook to listen for deployment status changes via WebSocket.
 * When a change is detected, triggers an Inertia partial reload to get fresh data.
 *
 * This approach keeps WebSocket payloads minimal - only notifications, not heavy data.
 *
 * @param reloadOnly - Optional array of page props to reload (default: ['deployment'])
 */
export function useDeploymentUpdates(deploymentId: number | string, reloadOnly: string[] = ['deployment']) {
    useEcho(
        `deployments.${deploymentId}`,
        '.deployment.status.changed',
        () => {
            router.reload({ only: reloadOnly });
        },
        [reloadOnly],
    );
}
