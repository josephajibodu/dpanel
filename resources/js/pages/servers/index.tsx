import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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
import { useServersListUpdates } from '@/hooks/use-servers-list-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Server } from '@/types/server';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    BookOpenIcon,
    EyeIcon,
    MoreVerticalIcon,
    PlusIcon,
    SearchIcon,
    ServerIcon,
    Trash2Icon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface Props {
    servers: {
        data: Server[];
        links?: unknown;
        meta?: PaginationMeta;
    };
}

const DOCS_URL = 'https://laravel.com/docs/starter-kits#react';

export default function ServersIndex({ servers }: Props) {
    const { auth } = usePage<SharedData>().props;
    const userId = auth.user?.id ?? 0;
    const teamPath = useTeamPath();
    useServersListUpdates(userId);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Servers',
            href: teamPath('/servers'),
        },
    ];

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [serverToDelete, setServerToDelete] = useState<Server | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);
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

        setIsDeleting(true);
        router.delete(teamPath(`/servers/${serverToDelete.id}`), {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setServerToDelete(null);
            },
        });
    };

    const { prevUrl, nextUrl } = getPaginationUrls(servers.links);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Servers</h1>
                        <p className="text-muted-foreground text-sm">
                            All of the servers of your project listed here.
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={DOCS_URL} target="_blank" rel="noreferrer">
                                <BookOpenIcon className="mr-2 h-4 w-4" />
                                Docs
                            </a>
                        </Button>
                        <Button asChild>
                            <Link href={teamPath('/servers/create')}>
                                <PlusIcon className="mr-2 h-4 w-4" />
                                Create server
                            </Link>
                        </Button>
                    </div>
                </div>

                {servers.data.length === 0 ? (
                    <EmptyState
                        icon={ServerIcon}
                        title="No servers yet"
                        description="Create your first server to get started with deploying applications."
                        action={
                            <Button asChild>
                                <Link href={teamPath('/servers/create')}>
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Create Server
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <div className="w-full max-w-sm">
                            <div className="relative">
                                <SearchIcon className="text-muted-foreground absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" />
                                <Input
                                    type="search"
                                    placeholder="Search..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-9"
                                    aria-label="Search servers"
                                />
                            </div>
                        </div>

                        <Card>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">ID</TableHead>
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
                                            <TableCell colSpan={6} className="h-24 text-center">
                                                <span className="text-muted-foreground text-sm">
                                                    No servers match your search.
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        filteredServers.map((server) => (
                                            <TableRow key={server.id}>
                                                <TableCell className="font-mono text-muted-foreground text-xs">
                                                    {server.id}
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={teamPath(`/servers/${server.id}`)}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {server.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {server.ip_address ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {format(new Date(server.created_at), 'yyyy-MM-dd HH:mm:ss')}
                                                </TableCell>
                                                <TableCell>
                                                    <ServerStatusBadge
                                                        status={server.status}
                                                        statusLabel={server.status_label}
                                                        statusColor={server.status_color}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button variant="outline" size="icon" className="h-8 w-8" asChild>
                                                            <Link href={teamPath(`/servers/${server.id}`)} aria-label="View server">
                                                                <EyeIcon className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="outline" size="icon" className="h-8 w-8">
                                                                    <MoreVerticalIcon className="h-4 w-4" />
                                                                    <span className="sr-only">Actions</span>
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem asChild>
                                                                    <Link href={teamPath(`/servers/${server.id}`)}>
                                                                        View Details
                                                                    </Link>
                                                                </DropdownMenuItem>
                                                                {server.status !== 'deleting' && (
                                                                    <DropdownMenuItem
                                                                        onClick={() => handleDelete(server)}
                                                                        className="text-destructive focus:text-destructive"
                                                                    >
                                                                        <Trash2Icon className="mr-2 h-4 w-4" />
                                                                        Delete
                                                                    </DropdownMenuItem>
                                                                )}
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>

                            <Pagination
                                meta={servers.meta}
                                prevUrl={prevUrl}
                                nextUrl={nextUrl}
                                resultsLabel="results"
                            />
                        </Card>
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
                loading={isDeleting}
            />
        </AppLayout>
    );
}
