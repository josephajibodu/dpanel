import { usePage } from '@inertiajs/react';

import {
    BreadcrumbSwitcherWrapper,
    switchToItem,
    type BreadcrumbSwitcherItem,
} from '@/components/breadcrumb-switcher';
import { useTeamPath } from '@/hooks/use-team-path';
import { resolveCurrentServer } from '@/components/server-switcher';
import {
    type BreadcrumbItem as BreadcrumbItemType,
    type SharedData,
} from '@/types';

/**
 * Normalises the `site` page prop which can arrive as either
 * `{ data: { id, domain, ... } }` (resource-wrapped) or the model directly.
 */
export function resolveCurrentSite(prop: unknown): { id: number; domain: string } | null {
    if (!prop || typeof prop !== 'object') {
        return null;
    }

    const p = prop as Record<string, unknown>;

    if (p.data && typeof p.data === 'object') {
        const d = p.data as Record<string, unknown>;
        if (typeof d.id === 'number' && typeof d.domain === 'string') {
            return { id: d.id, domain: d.domain };
        }
    }

    if (typeof p.id === 'number' && typeof p.domain === 'string') {
        return { id: p.id, domain: p.domain };
    }

    return null;
}

/**
 * Returns the index of the breadcrumb that represents the site overview,
 * identified by an href ending in `/servers/{id}/sites/{siteId}`.
 */
export function findSiteBreadcrumbIndex(breadcrumbs: BreadcrumbItemType[]): number {
    return breadcrumbs.findIndex((b) => /\/servers\/\d+\/sites\/\d+$/.test(b.href));
}

export function SiteBreadcrumbSwitcher({
    breadcrumb,
    isLast,
}: {
    breadcrumb: BreadcrumbItemType;
    isLast: boolean;
}) {
    const { sites } = usePage<SharedData>().props;
    const pageProps = usePage().props as Record<string, unknown>;
    const teamPath = useTeamPath();

    const items: BreadcrumbSwitcherItem[] = sites.map((site) => ({
        id: site.id,
        label: site.domain,
    }));

    return (
        <BreadcrumbSwitcherWrapper
            breadcrumb={breadcrumb}
            isLast={isLast}
            resolveCurrent={() => {
                const currentSite = resolveCurrentSite(pageProps.site);

                if (!currentSite) {
                    return null;
                }

                return { id: currentSite.id, label: currentSite.domain };
            }}
            menuLabel="Switch site"
            items={items}
            onSelect={(site) => {
                const currentSite = resolveCurrentSite(pageProps.site);
                const currentServer = resolveCurrentServer(pageProps.server);

                if (!currentSite || !currentServer) {
                    return;
                }

                switchToItem(
                    teamPath(`/servers/${currentServer.id}/sites/${site.id}`),
                    site,
                    currentSite.id,
                );
            }}
        />
    );
}
