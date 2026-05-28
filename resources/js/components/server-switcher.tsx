import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, ServerIcon } from 'lucide-react';

import {
    BreadcrumbItem,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type BreadcrumbItem as BreadcrumbItemType, type ServerSummary, type SharedData } from '@/types';

/**
 * Normalises the `server` page prop which can arrive as either
 * `{ data: { id, name, ... } }` (resource-wrapped) or the model directly.
 */
function resolveCurrentServer(prop: unknown): { id: number; name: string } | null {
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

/**
 * Inline breadcrumb item that opens a server switcher dropdown.
 * Renders as a `<BreadcrumbItem>` so it slots directly into a breadcrumb list.
 */
export function ServerBreadcrumbSwitcher({ isLast }: { isLast: boolean }) {
    const { servers } = usePage<SharedData>().props;
    const pageProps = usePage().props as Record<string, unknown>;
    const currentServer = resolveCurrentServer(pageProps.server);

    if (!currentServer) {
        return null;
    }

    const switchServer = (server: ServerSummary) => {
        if (server.id === currentServer.id) {
            return;
        }

        const teamSlug = window.location.pathname.split('/')[1];
        router.visit(`/${teamSlug}/servers/${server.id}`);
    };

    return (
        <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
                <DropdownMenu>
                    <DropdownMenuTrigger className="text-foreground hover:text-foreground inline-flex cursor-pointer items-center gap-1 font-normal outline-none">
                        {currentServer.name}
                        <ChevronsUpDown className="size-3 opacity-50" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="min-w-52">
                        <DropdownMenuLabel className="text-muted-foreground text-xs">
                            Switch server
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {servers.map((server) => (
                            <DropdownMenuItem
                                key={server.id}
                                onClick={() => switchServer(server)}
                                className="cursor-pointer gap-2"
                            >
                                <ServerIcon className="text-muted-foreground size-3.5 shrink-0" />
                                <span className="truncate">{server.name}</span>
                                {server.id === currentServer.id && (
                                    <Check className="ml-auto size-3.5 shrink-0" />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </BreadcrumbItem>
        </>
    );
}
