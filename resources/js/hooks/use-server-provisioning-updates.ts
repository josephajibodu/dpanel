import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Server } from '@/types/server';

interface ServerStatusEvent {
    server?: Server | { data?: Server };
    previous_status?: string;
    current_status?: string;
    previous_provisioning_step?: number | null;
    current_provisioning_step?: number | null;
}

function isProvisioningLifecycle(status: Server['status']): boolean {
    return ['pending', 'creating', 'provisioning'].includes(status);
}

function extractServer(payload?: Server | { data?: Server }): Server | undefined {
    return (payload as { data?: Server })?.data ?? (payload as Server | undefined);
}

export function useServerProvisioningUpdates(initialServer: Server) {
    const [server, setServer] = useState<Server>(initialServer);
    const connectionState = useConnectionStatus();
    const pollingIntervalRef = useRef<number | null>(null);

    const shouldPollFallback = useMemo(() => {
        return isProvisioningLifecycle(server.status) && connectionState !== 'connected';
    }, [connectionState, server.status]);

    useEffect(() => {
        setServer(initialServer);
    }, [initialServer]);

    useEcho<ServerStatusEvent>(
        `server.${initialServer.id}`,
        '.server.status.changed',
        (event) => {
            const incomingServer = extractServer(event.server);
            if (!incomingServer || incomingServer.id !== initialServer.id) {
                return;
            }

            if (event.current_status === 'error' && event.previous_status !== 'error') {
                toast.error(`Server provisioning failed: ${incomingServer.name}`, {
                    description: incomingServer.error_message ?? 'An unexpected error occurred during provisioning.',
                    duration: 10000,
                });
            }

            setServer((previousServer) => ({
                ...previousServer,
                ...incomingServer,
            }));
        },
        [initialServer.id],
    );

    useEcho<{ server?: Server }>(
        `server.${initialServer.id}`,
        '.server.sites.updated',
        (event) => {
            const incomingServer = extractServer(event.server);
            if (!incomingServer || incomingServer.id !== initialServer.id) {
                return;
            }

            setServer((previousServer) => ({
                ...previousServer,
                ...incomingServer,
            }));
        },
        [initialServer.id],
    );

    useEcho(
        `server.${initialServer.id}`,
        '.deployment.status.changed',
        () => {
            router.reload({
                only: ['server'],
                preserveScroll: true,
            });
        },
        [initialServer.id],
    );

    useEffect(() => {
        if (!shouldPollFallback) {
            if (pollingIntervalRef.current) {
                window.clearInterval(pollingIntervalRef.current);
                pollingIntervalRef.current = null;
            }

            return;
        }

        pollingIntervalRef.current = window.setInterval(() => {
            router.reload({
                only: ['server'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 5000);

        return () => {
            if (pollingIntervalRef.current) {
                window.clearInterval(pollingIntervalRef.current);
                pollingIntervalRef.current = null;
            }
        };
    }, [shouldPollFallback]);

    return {
        server,
        connectionState,
    };
}
