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

    useEffect(() => {
        // Only subscribe if Echo is available
        if (!window.Echo || isSubscribedRef.current) {
            return;
        }

        const channel = window.Echo.private(`deployments.${deploymentId}`);
        channelRef.current = channel;
        isSubscribedRef.current = true;

        // Listen for new log lines - append incrementally
        channel.listen('.output', (event: any) => {
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
                channelRef.current.stopListening('.output');
                window.Echo.leave(`deployments.${deploymentId}`);
                isSubscribedRef.current = false;
            }
        };
    }, [deploymentId]);

    // Update logs when initialLogs change (e.g., from partial reload)
    useEffect(() => {
        if (initialLogs.length > logs.length) {
            setLogs(initialLogs);
        }
    }, [initialLogs]);

    return logs;
}
