import { useEffect, useRef, useState } from 'react';

export interface DeploymentLogLine {
    id?: number;
    type: 'output' | 'error' | 'info' | 'success';
    message: string;
    timestamp?: string;
}

/**
 * Hook to listen for deployment log output via WebSocket.
 * Logs are streamed incrementally, so we append them to the existing logs.
 *
 * For status changes, we rely on useDeploymentUpdates to trigger partial reloads.
 */
export function useDeploymentLogs(
    deploymentId: number | string,
    initialLogs: DeploymentLogLine[] = [],
) {
    const [logs, setLogs] = useState<DeploymentLogLine[]>(initialLogs);
    const channelRef = useRef<any>(null);
    const isSubscribedRef = useRef(false);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        // Only subscribe if Echo is available
        if (!window.Echo || isSubscribedRef.current) {
            if (debugEnabled && !window.Echo) {
                console.warn('[realtime][deployments] Echo not available for log stream');
            }
            return;
        }

        if (debugEnabled) {
            console.debug('[realtime][deployments] subscribing to deployment log channel', {
                deploymentId,
            });
        }

        const channel = window.Echo.private(`deployments.${deploymentId}`);
        channelRef.current = channel;
        isSubscribedRef.current = true;

        channel.subscribed(() => {
            if (debugEnabled) {
                console.debug('[realtime][deployments] subscribed to deployment log channel', {
                    deploymentId,
                });
            }
        });

        channel.error((error: unknown) => {
            console.error('[realtime][deployments] log channel error', {
                deploymentId,
                error,
            });
        });

        // Listen for new log lines - append incrementally
        channel.listen('.output', (event: any) => {
            if (debugEnabled) {
                console.debug('[realtime][deployments] output event received', {
                    deploymentId,
                    event,
                });
            }

            setLogs((prev) => [
                ...prev,
                {
                    type: event.type || 'output',
                    message: event.line,
                    timestamp: event.timestamp,
                },
            ]);
        });

        return () => {
            if (channelRef.current) {
                if (debugEnabled) {
                    console.debug('[realtime][deployments] leaving deployment log channel', {
                        deploymentId,
                    });
                }
                channelRef.current.stopListening('.output');
                window.Echo.leave(`deployments.${deploymentId}`);
                isSubscribedRef.current = false;
            }
        };
    }, [debugEnabled, deploymentId]);

    // Update logs when initialLogs change (e.g., from partial reload)
    useEffect(() => {
        if (initialLogs.length > logs.length) {
            setLogs(initialLogs);
        }
    }, [initialLogs]);

    return logs;
}
