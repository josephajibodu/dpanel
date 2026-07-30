import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

interface SiteCommandRunStatusEvent {
    site_command_run_id?: number;
    site_id?: number;
    status?: string;
}

export function useServerCommandRunUpdates(serverId: number, siteId?: number) {
    useEcho<SiteCommandRunStatusEvent>(
        `server.${serverId}`,
        '.site.command-run.status.changed',
        (event) => {
            if (siteId != null && event.site_id != null && event.site_id !== siteId) {
                return;
            }

            router.reload({
                only: ['commandRuns'],
                preserveScroll: true,
            });
        },
        [siteId],
    );
}
