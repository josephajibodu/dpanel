import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

interface SiteCommandRunStatusEvent {
    site_command_run_id?: number;
    site_id?: number;
    status?: string;
}

interface CommandRunsChannel {
    listen(event: string, callback: (event: SiteCommandRunStatusEvent) => void): void;
    stopListening(event: string): void;
}

export function useServerCommandRunUpdates(serverId: number, siteId?: number) {
    const channelRef = useRef<CommandRunsChannel | null>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!serverId || !window.Echo) {
            return;
        }

        const channel = window.Echo.private(`server.${serverId}`);
        channelRef.current = channel;

        channel.listen('.site.command-run.status.changed', (event: SiteCommandRunStatusEvent) => {
            if (siteId != null && event.site_id != null && event.site_id !== siteId) {
                if (debugEnabled) {
                    console.debug('[realtime][command-runs] ignoring command run update for other site', {
                        eventSiteId: event.site_id,
                        currentSiteId: siteId,
                    });
                }
                return;
            }

            if (debugEnabled) {
                console.debug('[realtime][command-runs] status changed, reloading commandRuns', {
                    commandRunId: event.site_command_run_id,
                    siteId: event.site_id,
                    status: event.status,
                });
            }

            router.reload({
                only: ['commandRuns'],
                preserveScroll: true,
            });
        });

        return () => {
            if (channelRef.current) {
                channelRef.current.stopListening('.site.command-run.status.changed');
                window.Echo.leave(`server.${serverId}`);
            }
        };
    }, [debugEnabled, serverId, siteId]);
}
