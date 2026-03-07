import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { Server } from '@/types/server';

type RealtimeConnectionState = 'connected' | 'connecting' | 'disconnected';

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

export function useServerProvisioningUpdates(initialServer: Server) {
    const [server, setServer] = useState<Server>(initialServer);
    const [connectionState, setConnectionState] = useState<RealtimeConnectionState>('connecting');
    const channelRef = useRef<any>(null);
    const pollingIntervalRef = useRef<number | null>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    const shouldPollFallback = useMemo(() => {
        return isProvisioningLifecycle(server.status) && connectionState !== 'connected';
    }, [connectionState, server.status]);

    useEffect(() => {
        setServer(initialServer);
    }, [initialServer]);

    useEffect(() => {
        if (!window.Echo) {
            setConnectionState('disconnected');
            return;
        }

        const pusherConnection = window.Echo.connector?.pusher?.connection;
        const onStateChange = (states: { current: string }) => {
            if (states.current === 'connected') {
                setConnectionState('connected');
                return;
            }

            if (states.current === 'connecting') {
                setConnectionState('connecting');
                return;
            }

            setConnectionState('disconnected');
        };

        pusherConnection?.bind('state_change', onStateChange);

        const channel = window.Echo.private(`server.${initialServer.id}`);
        channelRef.current = channel;

        channel.subscribed(() => {
            setConnectionState('connected');
            if (debugEnabled) {
                console.debug('[realtime][server] subscribed', { serverId: initialServer.id });
            }
        });

        channel.error((error: unknown) => {
            setConnectionState('disconnected');
            console.error('[realtime][server] channel error', {
                serverId: initialServer.id,
                error,
            });
        });

        channel.listen('.server.status.changed', (event: ServerStatusEvent) => {
            const incomingServer = (event.server as { data?: Server })?.data ?? (event.server as Server | undefined);

            if (!incomingServer || incomingServer.id !== initialServer.id) {
                return;
            }

            if (debugEnabled) {
                console.debug('[realtime][server] status update', {
                    serverId: incomingServer.id,
                    previousStatus: event.previous_status,
                    currentStatus: event.current_status,
                    previousProvisioningStep: event.previous_provisioning_step,
                    currentProvisioningStep: event.current_provisioning_step,
                });
            }

            setServer((previousServer) => ({
                ...previousServer,
                ...incomingServer,
            }));
        });

        return () => {
            channel.stopListening('.server.status.changed');
            window.Echo.leave(`server.${initialServer.id}`);
            pusherConnection?.unbind('state_change', onStateChange);
        };
    }, [debugEnabled, initialServer.id]);

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
