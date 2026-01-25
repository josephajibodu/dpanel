import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Hook to listen for deployment status changes via WebSocket.
 * When a change is detected, triggers an Inertia partial reload to get fresh data.
 *
 * This approach keeps WebSocket payloads minimal - only notifications, not heavy data.
 */
export function useDeploymentUpdates(deploymentId: number | string) {
    const channelRef = useRef<any>(null);
    const isSubscribedRef = useRef(false);

    useEffect(() => {
        // Only subscribe if Echo is available
        if (!window.Echo || isSubscribedRef.current) {
            return;
        }

        const channel = window.Echo.private(`deployments.${deploymentId}`);
        channelRef.current = channel;
        isSubscribedRef.current = true;

        // Listen for status changes - trigger partial reload
        channel.listen('.deployment.status.changed', (event: any) => {
            // Use Inertia's partial reload to get fresh deployment data
            router.reload({
                only: ['deployment'],
                preserveState: true,
                preserveScroll: true,
            });
        });

        return () => {
            if (channelRef.current) {
                channelRef.current.stopListening('.deployment.status.changed');
                window.Echo.leave(`deployments.${deploymentId}`);
                isSubscribedRef.current = false;
            }
        };
    }, [deploymentId]);
}
