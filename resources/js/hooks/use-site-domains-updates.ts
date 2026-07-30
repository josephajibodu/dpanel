import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

export function useSiteDomainsUpdates(siteId: number) {
    useEcho(`site.${siteId}`, '.site.domains.updated', () => {
        router.reload({
            only: ['domains'],
            preserveScroll: true,
        });
    });
}
