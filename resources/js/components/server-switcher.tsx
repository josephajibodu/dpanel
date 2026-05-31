import { usePage } from '@inertiajs/react';

import {
    BreadcrumbSwitcherWrapper,
    switchToItem,
    type BreadcrumbSwitcherItem,
} from '@/components/breadcrumb-switcher';
import { useTeamPath } from '@/hooks/use-team-path';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';

/**
 * Normalises the `server` page prop which can arrive as either
 * `{ data: { id, name, ... } }` (resource-wrapped) or the model directly.
 */
export function resolveCurrentServer(prop: unknown): { id: number; name: string } | null {
    if (!prop || typeof prop !== 'object') {
        return null;
    }

    const p = prop as Record<string, unknown>;

    if (p.data && typeof p.data === 'object') {
        const d = p.data as Record<string, unknown>;
        if (typeof d.id === 'number' && typeof d.name === 'string') {
            return { id: d.id, name: d.name };
        }
    }

    if (typeof p.id === 'number' && typeof p.name === 'string') {
        return { id: p.id, name: p.name };
    }

    return null;
}

/**
 * Returns the index of the breadcrumb that represents the server,
 * identified by an href ending in `/servers/{id}`.
 */
export function findServerBreadcrumbIndex(breadcrumbs: BreadcrumbItemType[]): number {
    return breadcrumbs.findIndex((b) => /\/servers\/\d+$/.test(b.href));
}

export function ServerBreadcrumbSwitcher({
    breadcrumb,
    isLast,
}: {
    breadcrumb: BreadcrumbItemType;
    isLast: boolean;
}) {
    const { servers } = usePage<SharedData>().props;
    const pageProps = usePage().props as Record<string, unknown>;
    const teamPath = useTeamPath();

    const items: BreadcrumbSwitcherItem[] = servers.map((server) => ({
        id: server.id,
        label: server.name,
    }));

    return (
        <BreadcrumbSwitcherWrapper
            breadcrumb={breadcrumb}
            isLast={isLast}
            resolveCurrent={() => {
                const currentServer = resolveCurrentServer(pageProps.server);

                if (!currentServer) {
                    return null;
                }

                return { id: currentServer.id, label: currentServer.name };
            }}
            menuLabel="Switch server"
            items={items}
            onSelect={(server) => {
                const currentServer = resolveCurrentServer(pageProps.server);

                if (!currentServer) {
                    return;
                }

                switchToItem(teamPath(`/servers/${server.id}`), server, currentServer.id);
            }}
        />
    );
}
