import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export function useSiteDomainsUpdates(siteId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!siteId || !window.Echo) {
            return;
        }

        const channel = window.Echo.private(`site.${siteId}`);
        channelRef.current = channel;

        channel.error((error: unknown) => {
            console.error('[realtime][domains] channel error', { siteId, error });
        });

        const reload = () => {
            if (debugEnabled) {
                console.debug('[realtime][domains] reloading domains', { siteId });
            }
            router.reload({
                only: ['domains'],
                preserveScroll: true,
            });
        };

        channel.listen('.site.domains.updated', reload);

        return () => {
            if (channelRef.current) {
                channelRef.current.stopListening('.site.domains.updated');
                window.Echo.leave(`site.${siteId}`);
            }
        };
    }, [debugEnabled, siteId]);
}
