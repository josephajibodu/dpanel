import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
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
import { useServerSitesListUpdates } from '@/hooks/use-server-sites-list-updates';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Server } from '@/types/server';
import { type Site } from '@/types/site';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    EyeIcon,
    GlobeIcon,
    MoreVerticalIcon,
    PlusIcon,
    SearchIcon,
    Trash2Icon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface Props {
    server: { data: Server } | Server;
    sites: {
        data: Site[];
        links?: unknown;
        meta?: PaginationMeta;
    };
}

export default function ServerSitesIndex({ server: serverProp, sites }: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    useServerSitesListUpdates(server.id);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [siteToDelete, setSiteToDelete] = useState<Site | null>(null);
    const [deletingIds, setDeletingIds] = useState<number[]>([]);
    const [search, setSearch] = useState('');

    const filteredSites = useMemo(() => {
        if (!search.trim()) return sites.data;
        const q = search.toLowerCase().trim();
        return sites.data.filter(
            (s) =>
                s.domain.toLowerCase().includes(q) ||
                (s.project_type_label ?? '').toLowerCase().includes(q),
        );
    }, [sites.data, search]);

    const handleDelete = (site: Site) => {
        setSiteToDelete(site);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!siteToDelete) return;

        const id = siteToDelete.id;
        setDeleteDialogOpen(false);
        setSiteToDelete(null);
        setDeletingIds((prev) => [...prev, id]);

        router.delete(teamPath(`/servers/${server.id}/sites/${id}`), {
            preserveScroll: true,
            onSuccess: () => setDeletingIds((prev) => prev.filter((x) => x !== id)),
            onError: () => setDeletingIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Sites', href: teamPath(`/servers/${server.id}/sites`) },
    ];

    const { prevUrl, nextUrl } = getPaginationUrls(sites.links);

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(currentTeam?.slug ?? '', server.id)}
        >
            <Head title={`Sites - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Sites
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Sites on {server.name}.
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <Button asChild>
                            <Link href={teamPath(`/servers/${server.id}/sites/create`)}>
                                <PlusIcon className="mr-2 h-4 w-4" />
                                Add site
                            </Link>
                        </Button>
                    </div>
                </div>

                {sites.data.length === 0 ? (
                    <EmptyState
                        icon={GlobeIcon}
                        title="No sites yet"
                        description="Create your first site on this server."
                        action={
                            <Button asChild>
                                <Link href={teamPath(`/servers/${server.id}/sites/create`)}>
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Add Site
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
                                    placeholder="Search sites..."
                                    value={search}
                                    onChange={(e) =>
                                        setSearch(e.target.value)
                                    }
                                    className="pl-9"
                                    aria-label="Search sites"
                                />
                            </div>
                        </div>

                        <Card>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">ID</TableHead>
                                        <TableHead>Domain</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Created at</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-[70px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredSites.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center"
                                            >
                                                <span className="text-muted-foreground text-sm">
                                                    No sites match your search.
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        filteredSites.map((site) => {
                                            const isDeleting =
                                                deletingIds.includes(site.id) ||
                                                site.status === 'deleting';
                                            return (
                                            <TableRow key={site.id}>
                                                <TableCell className="font-mono text-muted-foreground text-xs">
                                                    {site.id}
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={teamPath(`/servers/${server.id}/sites/${site.id}`)}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {site.domain}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {site.project_type_label ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {format(
                                                        new Date(
                                                            site.created_at,
                                                        ),
                                                        'yyyy-MM-dd HH:mm:ss',
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <SiteStatusBadge
                                                        status={isDeleting ? 'deleting' : site.status}
                                                        statusLabel={isDeleting ? 'Deleting...' : site.status_label}
                                                        statusColor={isDeleting ? 'orange' : site.status_color}
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
                                                                href={teamPath(`/servers/${server.id}/sites/${site.id}`)}
                                                                aria-label="View site"
                                                            >
                                                                <EyeIcon className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button
                                                                    variant="outline"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                >
                                                                    <MoreVerticalIcon className="h-4 w-4" />
                                                                    <span className="sr-only">
                                                                        Actions
                                                                    </span>
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem asChild>
                                                                    <Link
                                                                        href={teamPath(`/servers/${server.id}/sites/${site.id}`)}
                                                                    >
                                                                        View
                                                                    </Link>
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem asChild>
                                                                    <Link
                                                                        href={teamPath(`/servers/${server.id}/sites/${site.id}/edit`)}
                                                                    >
                                                                        Edit
                                                                    </Link>
                                                                </DropdownMenuItem>
                                                                {!isDeleting && site.status !== 'installing' && (
                                                                    <DropdownMenuItem
                                                                        onClick={() =>
                                                                            handleDelete(site)
                                                                        }
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
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>

                            <Pagination
                                meta={sites.meta}
                                prevUrl={prevUrl}
                                nextUrl={nextUrl}
                                resultsLabel="sites"
                            />
                        </Card>
                    </>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete Site"
                description={`Are you sure you want to delete "${siteToDelete?.domain}"? This will permanently remove the site and its data.`}
                confirmLabel="Delete Site"
                variant="destructive"
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
