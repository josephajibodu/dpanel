import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

export interface DeploymentLogLine {
    id?: number;
    type: 'output' | 'error' | 'info' | 'success';
    message: string;
    timestamp?: string;
}

interface DeploymentOutputEvent {
    line: string;
    type?: DeploymentLogLine['type'];
    timestamp?: string;
}

/**
 * Hook to listen for deployment log output via WebSocket.
 * Logs are streamed incrementally, so we append them to the existing logs.
 *
 * For status changes, we rely on useDeploymentUpdates to trigger partial reloads.
 */
export function useDeploymentLogs(deploymentId: number | string, initialLogs: DeploymentLogLine[] = []) {
    const [logs, setLogs] = useState<DeploymentLogLine[]>(initialLogs);

    useEcho<DeploymentOutputEvent>(`deployments.${deploymentId}`, '.output', (event) => {
        setLogs((prev) => [
            ...prev,
            {
                type: event.type || 'output',
                message: event.line,
                timestamp: event.timestamp,
            },
        ]);
    });

    // Update logs when initialLogs change (e.g., from partial reload)
    useEffect(() => {
        if (initialLogs.length > logs.length) {
            setLogs(initialLogs);
        }
    }, [initialLogs]);

    return logs;
}
