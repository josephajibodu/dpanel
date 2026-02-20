import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ArrowLeftIcon,
    ExternalLinkIcon,
    GitBranchIcon,
    GlobeIcon,
    MoreVerticalIcon,
    RocketIcon,
    ServerIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Site } from '@/types/site';

interface Props {
    site: {
        data: Site & {
            server?: {
                id: number;
                name: string;
                ip_address: string;
            };
        };
    };
}

export default function SitesShow({ site }: Props) {
    const { data: siteData } = site;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: siteData.server?.name || 'Server', href: `/servers/${siteData.server?.id}` },
        { title: siteData.domain, href: `/sites/${siteData.id}` },
    ];

    const handleDelete = () => {
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        setIsDeleting(true);
        router.delete(`/sites/${siteData.id}`, {
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
                siteData.server?.id ?? '',
                siteData.id,
            )}
        >
            <Head title={siteData.domain} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={`/servers/${siteData.server?.id}`}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">{siteData.domain}</h1>
                            <SiteStatusBadge status={siteData.status} statusLabel={siteData.status_label} statusColor={siteData.status_color} />
                        </div>
                        <p className="text-muted-foreground text-sm">{siteData.project_type_label}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <a href={`https://${siteData.domain}`} target="_blank" rel="noopener noreferrer">
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
                                    <Link href={`/sites/${siteData.id}/edit`}>Edit Site</Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onClick={handleDelete} className="text-destructive focus:text-destructive" disabled={siteData.status === 'installing'}>
                                    <Trash2Icon className="mr-2 h-4 w-4" />
                                    Delete Site
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                {/* Overview */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Site Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <GlobeIcon className="h-5 w-5" />
                                Site Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Domain</span>
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">{siteData.domain}</span>
                                    <CopyButton value={siteData.domain} className="h-7 w-7" />
                                </div>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Project Type</span>
                                <span className="font-medium">{siteData.project_type_label}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">PHP Version</span>
                                <span className="font-medium">PHP {siteData.php_version}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Web Directory</span>
                                <code className="bg-muted rounded px-2 py-0.5 text-sm">{siteData.directory}</code>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Root Path</span>
                                <code className="bg-muted max-w-[200px] truncate rounded px-2 py-0.5 text-sm">{siteData.root_path}</code>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Created</span>
                                <span>{format(new Date(siteData.created_at), 'MMM d, yyyy HH:mm')}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Repository Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <GitBranchIcon className="h-5 w-5" />
                                Repository
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {siteData.repository ? (
                                <>
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Provider</span>
                                        <span className="font-medium">{siteData.repository_provider_label}</span>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Repository</span>
                                        <a
                                            href={siteData.repository_url || '#'}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-1 text-sm font-medium hover:underline"
                                        >
                                            {siteData.short_repository}
                                            <ExternalLinkIcon className="h-3 w-3" />
                                        </a>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Branch</span>
                                        <code className="bg-muted rounded px-2 py-0.5 text-sm">{siteData.branch}</code>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Auto Deploy</span>
                                        <span className={siteData.auto_deploy ? 'text-green-600' : 'text-muted-foreground'}>
                                            {siteData.auto_deploy ? 'Enabled' : 'Disabled'}
                                        </span>
                                    </div>
                                </>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-8 text-center">
                                    <GitBranchIcon className="text-muted-foreground mb-2 h-8 w-8" />
                                    <p className="text-muted-foreground text-sm">No repository connected.</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Server Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ServerIcon className="h-5 w-5" />
                                Server
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Name</span>
                                <Link href={`/servers/${siteData.server?.id}`} className="font-medium hover:underline">
                                    {siteData.server?.name}
                                </Link>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">IP Address</span>
                                <div className="flex items-center gap-2">
                                    <code className="bg-muted rounded px-2 py-0.5 text-sm">{siteData.server?.ip_address}</code>
                                    {siteData.server?.ip_address && <CopyButton value={siteData.server.ip_address} className="h-7 w-7" />}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Latest Deployment */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <RocketIcon className="h-5 w-5" />
                                Latest Deployment
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {siteData.latest_deployment ? (
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Status</span>
                                        <span
                                            className={`font-medium ${
                                                siteData.latest_deployment.status === 'finished'
                                                    ? 'text-green-600'
                                                    : siteData.latest_deployment.status === 'failed'
                                                      ? 'text-red-600'
                                                      : 'text-amber-600'
                                            }`}
                                        >
                                            {siteData.latest_deployment.status_label}
                                        </span>
                                    </div>
                                    {siteData.latest_deployment.commit_hash && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-muted-foreground">Commit</span>
                                            <code className="bg-muted rounded px-2 py-0.5 text-sm">
                                                {siteData.latest_deployment.commit_hash.slice(0, 7)}
                                            </code>
                                        </div>
                                    )}
                                    {siteData.latest_deployment.finished_at && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-muted-foreground">Deployed</span>
                                            <span>{format(new Date(siteData.latest_deployment.finished_at), 'MMM d, yyyy HH:mm')}</span>
                                        </div>
                                    )}
                                    {siteData.latest_deployment.duration_seconds && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-muted-foreground">Duration</span>
                                            <span>{siteData.latest_deployment.duration_seconds}s</span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-8 text-center">
                                    <RocketIcon className="text-muted-foreground mb-2 h-8 w-8" />
                                    <p className="text-muted-foreground text-sm">No deployments yet.</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
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
