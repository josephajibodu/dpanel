import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

import { type DeploymentLogLine } from '@/hooks/use-deployment-logs';

interface ProvisioningOutputEvent {
    line: string;
    type?: DeploymentLogLine['type'];
    timestamp?: string;
}

/**
 * Hook to listen for provisioning log output via WebSocket.
 * Logs are streamed incrementally, so we append them to the existing logs.
 *
 * For status/step changes, we rely on useServerProvisioningUpdates to trigger partial reloads.
 */
export function useServerProvisioningLogs(serverId: number, initialLogs: DeploymentLogLine[] = []) {
    const [logs, setLogs] = useState<DeploymentLogLine[]>(initialLogs);

    useEcho<ProvisioningOutputEvent>(`server.${serverId}`, '.provisioning.output', (event) => {
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
