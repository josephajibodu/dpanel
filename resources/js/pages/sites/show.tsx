import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    CalendarIcon,
    ExternalLinkIcon,
    GitBranchIcon,
    MoreVerticalIcon,
    PlayIcon,
    RocketIcon,
    ServerIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Deployment } from '@/types/deployment';
import { Site } from '@/types/site';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
            deployments?: (Deployment & { user?: { id: number; name: string } })[];
        };
    };
}

export default function SitesShow({ server: serverProp, site }: Props) {
    const server = serverProp?.data ?? serverProp;
    const siteData = (site?.data ?? site) as Props['site']['data'] | undefined;
    const serverId = server?.id ?? siteData?.server?.id;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    if (!siteData?.id) {
        return (
            <AppLayout breadcrumbs={[]}>
                <Head title="Site" />
                <div className="flex h-full flex-1 flex-col items-center justify-center gap-4 p-4">
                    <p className="text-muted-foreground text-sm">
                        Loading site...
                    </p>
                </div>
            </AppLayout>
        );
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server?.name || siteData.server?.name || 'Server', href: `/servers/${serverId}` },
        { title: siteData.domain, href: `/servers/${serverId}/sites/${siteData.id}` },
    ];

    const deployments = (siteData.deployments ?? []) as (Deployment & {
        user?: { id: number; name: string };
    })[];

    const handleDelete = () => {
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        setIsDeleting(true);
        router.delete(`/servers/${serverId}/sites/${siteData.id}`, {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(
                String(serverId ?? ''),
                siteData.id,
            )}
        >
            <Head title={siteData.domain} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {/* Header: no back arrow */}
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {siteData.domain}
                            </h1>
                            <SiteStatusBadge
                                status={siteData.status}
                                statusLabel={siteData.status_label}
                                statusColor={siteData.status_color}
                            />
                        </div>
                        {siteData.repository && (
                            <p className="text-muted-foreground mt-1 flex items-center gap-1.5 text-sm">
                                <GitBranchIcon className="h-4 w-4 shrink-0" />
                                <span className="truncate">
                                    {siteData.short_repository}:{siteData.branch}
                                </span>
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <Button variant="outline" asChild>
                            <a
                                href={`https://${siteData.domain}`}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLinkIcon className="mr-2 h-4 w-4" />
                                Visit Site
                            </a>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon">
                                    <MoreVerticalIcon className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <Link href={`/servers/${serverId}/sites/${siteData.id}/edit`}>
                                        Edit Site
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onClick={handleDelete}
                                    className="text-destructive focus:text-destructive"
                                    disabled={siteData.status === 'installing'}
                                >
                                    <Trash2Icon className="mr-2 h-4 w-4" />
                                    Delete Site
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                    {/* Left column: Deployments, Background processes, Scheduled jobs */}
                    <div className="space-y-6">
                        {/* Deployments */}
                        <Card id="deployments">
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2">
                                        <RocketIcon className="h-5 w-5" />
                                        Deployments
                                    </CardTitle>
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={`/servers/${serverId}/sites/${siteData.id}/deployments`}>
                                            View all
                                        </Link>
                                    </Button>
                                </div>
                                <CardDescription>
                                    Recent deployments for this site.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {deployments.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center space-y-3 rounded-lg border border-dashed py-10 text-center">
                                        <RocketIcon className="text-muted-foreground h-10 w-10" />
                                        <div>
                                            <p className="text-sm font-medium">
                                                No deployments yet
                                            </p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                Deployments will appear here once
                                                you trigger your first deployment.
                                            </p>
                                        </div>
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={`/servers/${serverId}/sites/${siteData.id}/deployments`}>
                                                Go to Deployments
                                            </Link>
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Status</TableHead>
                                                    <TableHead>Commit</TableHead>
                                                    <TableHead>Message</TableHead>
                                                    <TableHead>Deployed</TableHead>
                                                    <TableHead className="w-0" />
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {deployments.map((d) => (
                                                    <TableRow key={d.id}>
                                                        <TableCell>
                                                            <StatusBadge
                                                                status={d.status}
                                                                label={d.status_label}
                                                            />
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">
                                                            {d.commit_hash
                                                                ? d.commit_hash.slice(0, 7)
                                                                : '—'}
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground max-w-[200px] truncate text-sm">
                                                            {d.commit_message ?? '—'}
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground whitespace-nowrap text-sm">
                                                            {d.finished_at
                                                                ? format(
                                                                      new Date(d.finished_at),
                                                                      'MMM d, HH:mm',
                                                                  )
                                                                : d.started_at
                                                                  ? 'Running...'
                                                                  : '—'}
                                                            {d.user?.name && (
                                                                <span className="ml-1">
                                                                    by {d.user.name}
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/servers/${serverId}/sites/${siteData.id}/deployments/${d.id}`}
                                                                >
                                                                    View
                                                                </Link>
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Background processes */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <PlayIcon className="h-5 w-5" />
                                    Background processes
                                </CardTitle>
                                <CardDescription>
                                    Process managers and workers for this site.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-col items-center justify-center space-y-3 rounded-lg border border-dashed py-10 text-center">
                                    <PlayIcon className="text-muted-foreground h-10 w-10" />
                                    <div>
                                        <p className="text-sm font-medium">
                                            No background processes yet
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            Add process managers or workers when
                                            ready.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Scheduled jobs */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <CalendarIcon className="h-5 w-5" />
                                    Scheduled jobs
                                </CardTitle>
                                <CardDescription>
                                    Cron jobs and scheduled tasks for this site.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-col items-center justify-center space-y-3 rounded-lg border border-dashed py-10 text-center">
                                    <CalendarIcon className="text-muted-foreground h-10 w-10" />
                                    <div>
                                        <p className="text-sm font-medium">
                                            No scheduled jobs yet
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            Add cron jobs or scheduled tasks when
                                            ready.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column: Details sidebar */}
                    <div>
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <ServerIcon className="h-5 w-5" />
                                    Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5 text-sm">
                                <div className="space-y-2">
                                    <p className="text-muted-foreground text-xs font-medium uppercase">
                                        Server
                                    </p>
                                    <DetailRow
                                        label="Server"
                                        value={
                                            siteData.server?.name
                                                ? `#${siteData.server.id} ${siteData.server.name}`
                                                : `#${siteData.server?.id ?? '—'}`
                                        }
                                    />
                                    <DetailRow
                                        label="Site ID"
                                        value={String(siteData.id)}
                                        valueClassName="font-mono"
                                    />
                                    <DetailRow
                                        label="Framework"
                                        value={
                                            siteData.project_type_label ?? '—'
                                        }
                                    />
                                    <DetailRow
                                        label="PHP"
                                        value={`PHP ${siteData.php_version}`}
                                    />
                                    <DetailRow
                                        label="Public IP"
                                        value={
                                            siteData.server?.ip_address ?? '—'
                                        }
                                        valueClassName="font-mono"
                                    />
                                </div>

                                <div className="space-y-2 border-t pt-4">
                                    <p className="text-muted-foreground text-xs font-medium uppercase">
                                        Repository
                                    </p>
                                    {siteData.repository ? (
                                        <>
                                            <DetailRow
                                                label="Branch"
                                                value={siteData.branch}
                                                valueClassName="font-mono"
                                            />
                                            <DetailRow
                                                label="Auto deploy"
                                                value={
                                                    siteData.auto_deploy
                                                        ? 'Enabled'
                                                        : 'Disabled'
                                                }
                                            />
                                        </>
                                    ) : (
                                        <p className="text-muted-foreground text-xs">
                                            No repository connected.
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2 border-t pt-4">
                                    <p className="text-muted-foreground text-xs font-medium uppercase">
                                        Status
                                    </p>
                                    <p className="text-sm font-medium">
                                        {siteData.status_label}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        Created{' '}
                                        {format(
                                            new Date(siteData.created_at),
                                            'MMM d, yyyy',
                                        )}
                                    </p>
                                    <Link
                                        href={`/servers/${serverId}`}
                                        className="text-muted-foreground hover:text-foreground mt-2 block text-xs font-medium"
                                    >
                                        View server →
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete Site"
                description={`Are you sure you want to delete "${siteData.domain}"? This will permanently remove the site, its files, Nginx configuration, and any associated deploy keys. This action cannot be undone.`}
                confirmLabel="Delete Site"
                variant="destructive"
                onConfirm={confirmDelete}
                loading={isDeleting}
            />
        </AppLayout>
    );
}

interface DetailRowProps {
    label: string;
    value: string;
    valueClassName?: string;
}

function DetailRow({
    label,
    value,
    valueClassName,
}: DetailRowProps) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span
                className={`text-sm font-medium ${valueClassName ?? ''}`}
            >
                {value}
            </span>
        </div>
    );
}

const statusColors: Record<
    string,
    string
> = {
    pending: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    finished:
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    cancelled:
        'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
};

function StatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return (
        <span
            className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${
                statusColors[status] ?? 'bg-gray-100 text-gray-800'
            }`}
        >
            {label}
        </span>
    );
}
