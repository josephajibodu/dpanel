import { useForm } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';

import { useTeamPath } from '@/hooks/use-team-path';
import { ServerMetric } from '@/types/metric';

/** Safety net in case a refresh's SSH poll fails silently (no broadcast follows). */
const REFRESH_TIMEOUT_MS = 20000;

export function useServerMetrics(
    serverId: number,
    initialMetric: ServerMetric | null | undefined,
) {
    const teamPath = useTeamPath();
    const [metric, setMetric] = useState<ServerMetric | null>(
        initialMetric ?? null,
    );
    const [isRefreshing, setIsRefreshing] = useState(false);
    const timeoutRef = useRef<number | null>(null);
    const refreshForm = useForm({});

    const clearRefreshTimeout = () => {
        if (timeoutRef.current) {
            window.clearTimeout(timeoutRef.current);
            timeoutRef.current = null;
        }
    };

    useEcho<ServerMetric>(
        `server.${serverId}`,
        '.server.metrics.updated',
        (event) => {
            setMetric(event);
            setIsRefreshing(false);
            clearRefreshTimeout();
        },
        [serverId],
    );

    useEffect(() => clearRefreshTimeout, []);

    const refresh = () => {
        if (isRefreshing) {
            return;
        }

        setIsRefreshing(true);
        refreshForm.post(teamPath(`/servers/${serverId}/metrics/refresh`), {
            preserveScroll: true,
            preserveState: true,
            onError: () => setIsRefreshing(false),
        });

        timeoutRef.current = window.setTimeout(() => {
            setIsRefreshing(false);
        }, REFRESH_TIMEOUT_MS);
    };

    return { metric, isRefreshing, refresh };
}
