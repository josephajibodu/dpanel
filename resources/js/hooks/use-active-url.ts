import type { InertiaLinkProps } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';

import { toUrl } from '@/lib/utils';

function normalizePath(path: string): string {
    return path.replace(/\/$/, '') || '/';
}

export function useActiveUrl() {
    const page = usePage();
    const currentUrlPath = new URL(page.url, window?.location.origin).pathname;

    function urlIsActive(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
        options?: { exact?: boolean },
    ) {
        const urlToCompare = currentUrl ?? currentUrlPath;
        const comparePath = normalizePath(
            new URL(toUrl(urlToCheck), window?.location.origin).pathname,
        );
        const path = normalizePath(
            new URL(urlToCompare, window?.location.origin).pathname,
        );
        if (options?.exact) {
            return path === comparePath;
        }
        return (
            path === comparePath ||
            (comparePath !== '/' && path.startsWith(comparePath + '/'))
        );
    }

    return {
        currentUrl: currentUrlPath,
        urlIsActive,
    };
}
