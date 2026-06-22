import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export function useSiteNginxUpdates(siteId: number) {
    const channelRef = useRef<any>(null);
    const debugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

    useEffect(() => {
        if (!siteId || !window.Echo) {
            return;
        }

        const channel = window.Echo.private(`site.${siteId}`);
        channelRef.current = channel;

        channel.error((error: unknown) => {
            console.error('[realtime][nginx] channel error', { siteId, error });
        });

        const reload = () => {
            router.reload({
                only: ['nginxFiles', 'nginxHistory'],
                preserveScroll: true,
            });
        };

        channel.listen('.site.nginx.updated', reload);

        return () => {
            if (channelRef.current) {
                channelRef.current.stopListening('.site.nginx.updated');
                window.Echo.leave(`site.${siteId}`);
            }
        };
    }, [siteId]);
}
