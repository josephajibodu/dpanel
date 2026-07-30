import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

export function useSiteNginxUpdates(siteId: number) {
    useEcho(`site.${siteId}`, '.site.nginx.updated', () => {
        router.reload({
            only: ['nginxFiles', 'nginxHistory'],
            preserveScroll: true,
        });
    });
}
