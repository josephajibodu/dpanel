import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, ServerIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type ServerSummary, type SharedData } from '@/types';

/**
 * Normalises the `server` page prop which can arrive as either
 * `{ data: { id, name, ... } }` (resource-wrapped) or the model directly.
 */
function resolveCurrentServer(
    prop: unknown,
): { id: number; name: string } | null {
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

export function ServerSwitcher() {
    const { servers, currentTeam } = usePage<SharedData>().props;
    const pageProps = usePage().props as Record<string, unknown>;
    const currentServer = resolveCurrentServer(pageProps.server);

    // Only render on server-scoped pages
    if (!currentServer || !currentTeam) {
        return null;
    }

    const switchServer = (server: ServerSummary) => {
        if (server.id === currentServer.id) {
            return;
        }

        // Replace the server ID segment in the current URL so the user stays
        // on the same sub-section (e.g. /sites, /databases) on the new server.
        const newPath = window.location.pathname.replace(
            /\/servers\/\d+/,
            `/servers/${server.id}`,
        );

        router.visit(newPath);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground hover:text-foreground h-7 gap-1.5 px-2 text-xs font-medium"
                >
                    <ServerIcon className="size-3.5" />
                    <span className="max-w-36 truncate">{currentServer.name}</span>
                    <ChevronsUpDown className="size-3 opacity-60" />
                </Button>
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
    );
}
