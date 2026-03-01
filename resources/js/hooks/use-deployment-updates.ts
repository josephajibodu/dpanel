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
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        // Only subscribe if Echo is available
        if (!window.Echo || isSubscribedRef.current) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][deployments] Echo not available for status updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][deployments] subscribing to deployment status channel', {
                deploymentId,
            });
        }

        const channel = window.Echo.private(`deployments.${deploymentId}`);
        channelRef.current = channel;
        isSubscribedRef.current = true;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][deployments] subscribed to deployment status channel', {
                    deploymentId,
                });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][deployments] status channel error', {
                deploymentId,
                error,
            });
        });

        // Listen for status changes - trigger partial reload
        channel.listen('.deployment.status.changed', (event: any) => {
            if (debugEnabled) {
                console.debug('[realtime][deployments] status event received', {
                    deploymentId,
                    event,
                });
            }

            // Use Inertia's partial reload to get fresh deployment data
            router.reload({
                only: ['deployment'],
            });
        });

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][deployments] leaving deployment status channel', {
                        deploymentId,
                    });
                }
                channelRef.current.stopListening('.deployment.status.changed');
                window.Echo.leave(`deployments.${deploymentId}`);
                isSubscribedRef.current = false;
            }
        };
    }, [debugEnabled, deploymentId]);
}
