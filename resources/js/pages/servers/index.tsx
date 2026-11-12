import { Head, Link, router } from '@inertiajs/react';
import {
    BookOpenIcon,
    DotsThreeVerticalIcon,
    EyeIcon,
    PlusIcon,
    TrashIcon,
} from '@phosphor-icons/react';
import { format } from 'date-fns';
import { useMemo, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { AddServerOptions } from '@/components/dashboard/getting-started';
import { EmptyState } from '@/components/empty-state';
import { PageActions } from '@/components/page-actions';
import PlanUpgradeNotice from '@/components/plan-upgrade-notice';
import { SearchInput } from '@/components/search-input';
import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    getPaginationUrls,
    Pagination,
    type PaginationMeta,
} from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usePermission } from '@/hooks/use-permission';
import { usePlan } from '@/hooks/use-plan';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';

interface Props {
    servers: {
        data: Server[];
        links?: unknown;
        meta?: PaginationMeta;
    };
}

const DOCS_URL = 'https://docs.flitops.com/servers/creating-servers';

export default function ServersIndex({ servers }: Props) {
    const teamPath = useTeamPath();
    const canManageServers = usePermission()('manage_servers');
    const serverLimitMessage = usePlan()?.serverLimitMessage ?? null;
    const canAddServer = canManageServers && serverLimitMessage === null;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Servers',
            href: teamPath('/servers'),
        },
    ];

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [serverToDelete, setServerToDelete] = useState<Server | null>(null);
    const [deletingIds, setDeletingIds] = useState<number[]>([]);
    const [search, setSearch] = useState('');

    const filteredServers = useMemo(() => {
        if (!search.trim()) return servers.data;
        const q = search.toLowerCase().trim();
        return servers.data.filter(
            (s) =>
                s.name.toLowerCase().includes(q) ||
                (s.ip_address ?? '').toLowerCase().includes(q) ||
                s.region?.toLowerCase().includes(q),
        );
    }, [servers.data, search]);

    const handleDelete = (server: Server) => {
        setServerToDelete(server);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!serverToDelete) return;

        const id = serverToDelete.id;
        setDeleteDialogOpen(false);
        setServerToDelete(null);
        setDeletingIds((prev) => [...prev, id]);

        router.delete(teamPath(`/servers/${id}`), {
            preserveScroll: true,
            onSuccess: () =>
                setDeletingIds((prev) => prev.filter((x) => x !== id)),
            onError: () =>
                setDeletingIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    const { prevUrl, nextUrl } = getPaginationUrls(servers.links);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <PageActions>
                    <Button variant="outline" size="sm" asChild>
                        <a href={DOCS_URL} target="_blank" rel="noreferrer">
                            <BookOpenIcon className="h-4 w-4" />
                            Docs
                        </a>
                    </Button>
                    {canAddServer && servers.data.length > 0 && (
                        <Button asChild>
                            <Link href={teamPath('/servers/create')}>
                                <PlusIcon className="h-4 w-4" />
                                Create server
                            </Link>
                        </Button>
                    )}
                </PageActions>

                {canManageServers && serverLimitMessage && (
                    <PlanUpgradeNotice message={serverLimitMessage} />
                )}

                {servers.data.length === 0 && canAddServer ? (
                    <AddServerOptions />
                ) : servers.data.length === 0 ? (
                    <EmptyState
                        title="No servers yet"
                        description="Servers added to this team will appear here."
                    />
                ) : (
                    <>
                        <SearchInput
                            value={search}
                            onChange={setSearch}
                            aria-label="Search servers"
                        />

                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12 text-right">
                                            ID
                                        </TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>IP</TableHead>
                                        <TableHead>Created at</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-[70px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredServers.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center"
                                            >
                                                <span className="text-sm text-muted-foreground">
                                                    No servers match your
                                                    search.
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        filteredServers.map((server) => {
                                            const isDeleting =
                                                deletingIds.includes(
                                                    server.id,
                                                ) ||
                                                server.status === 'deleting';
                                            return (
                                                <TableRow key={server.id}>
                                                    <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                                        {server.id}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Link
                                                            href={teamPath(
                                                                `/servers/${server.id}`,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {server.name}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell>
                                                        {server.ip_address ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {format(
                                                            new Date(
                                                                server.created_at,
                                                            ),
                                                            'yyyy-MM-dd HH:mm:ss',
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <ServerStatusBadge
                                                            status={
                                                                isDeleting
                                                                    ? 'deleting'
                                                                    : server.status
                                                            }
                                                            statusLabel={
                                                                isDeleting
                                                                    ? 'Deleting...'
                                                                    : server.status_label
                                                            }
                                                            statusColor={
                                                                isDeleting
                                                                    ? 'orange'
                                                                    : server.status_color
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button
                                                                variant="outline"
                                                                size="icon"
                                                                className="h-8 w-8"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={teamPath(
                                                                        `/servers/${server.id}`,
                                                                    )}
                                                                    aria-label="View server"
                                                                >
                                                                    <EyeIcon className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger
                                                                    asChild
                                                                >
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        className="h-8 w-8"
                                                                    >
                                                                        <DotsThreeVerticalIcon className="h-4 w-4" />
                                                                        <span className="sr-only">
                                                                            Actions
                                                                        </span>
                                                                    </Button>
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    <DropdownMenuItem
                                                                        asChild
                                                                    >
                                                                        <Link
                                                                            href={teamPath(
                                                                                `/servers/${server.id}`,
                                                                            )}
                                                                        >
                                                                            View
                                                                            Details
                                                                        </Link>
                                                                    </DropdownMenuItem>
                                                                    {canManageServers &&
                                                                        !isDeleting && (
                                                                            <DropdownMenuItem
                                                                                onClick={() =>
                                                                                    handleDelete(
                                                                                        server,
                                                                                    )
                                                                                }
                                                                                className="text-destructive focus:text-destructive"
                                                                            >
                                                                                <TrashIcon className="h-4 w-4" />
                                                                                Delete
                                                                            </DropdownMenuItem>
                                                                        )}
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>

                            <Pagination
                                meta={servers.meta}
                                prevUrl={prevUrl}
                                nextUrl={nextUrl}
                                resultsLabel="results"
                            />
                        </>
                    </>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete Server"
                description={`Are you sure you want to delete "${serverToDelete?.name}"? This will permanently destroy the server and all its data.`}
                confirmLabel="Delete Server"
                variant="destructive"
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
