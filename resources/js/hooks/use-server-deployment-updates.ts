import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

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
export function useServerDeploymentUpdates(
    serverId: number,
    siteId?: number,
    reloadOnly: string[] = ['site', 'deployments'],
) {
    const channelRef = useRef<any>(null);
    const reloadOnlyRef = useRef(reloadOnly);
    reloadOnlyRef.current = reloadOnly;
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !window.Echo) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][deployments] Echo not available for server deployment updates');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][deployments] subscribing to server deployment channel', {
                serverId,
                siteId,
            });
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][deployments] subscribed to server deployment channel', {
                    serverId,
                });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][deployments] server deployment channel error', {
                serverId,
                error,
            });
        });

        channel.listen('.deployment.status.changed', (event: DeploymentStatusEvent) => {
            if (siteId != null && event.site_id != null && event.site_id !== siteId) {
                if (debugEnabled) {
                    console.debug('[realtime][deployments] ignoring deployment for other site', {
                        eventSiteId: event.site_id,
                        currentSiteId: siteId,
                    });
                }
                return;
            }

            if (debugEnabled) {
                console.debug('[realtime][deployments] deployment status changed, reloading', {
                    deploymentId: event.deployment_id,
                    siteId: event.site_id,
                });
            }

            router.reload({
                only: reloadOnlyRef.current,
                preserveScroll: true,
            });
        });

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][deployments] leaving server deployment channel', {
                        serverId,
                    });
                }
                channelRef.current.stopListening('.deployment.status.changed');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, serverId, siteId]);
}
