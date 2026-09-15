import { Head, Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ExternalLinkIcon,
    GitBranchIcon,
    Loader2Icon,
    MoreVerticalIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { ProvisioningStepTimeline } from '@/components/provisioning-step-timeline';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { StatusBadge } from '@/components/status-badge';
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
import { useServerDeploymentUpdates } from '@/hooks/use-server-deployment-updates';
import { useSiteProvisioningUpdates } from '@/hooks/use-site-provisioning-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Deployment } from '@/types/deployment';
import { Site } from '@/types/site';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
            deployments?: (Deployment & {
                user?: { id: number; name: string };
            })[];
        };
    };
}

export default function SitesShow({ server: serverProp, site }: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    const siteData = (site?.data ?? site) as Props['site']['data'] | undefined;
    const serverId = server?.id ?? siteData?.server?.id;
    const isProvisioning = ['pending', 'installing'].includes(
        siteData?.status ?? '',
    );
    useServerDeploymentUpdates(Number(serverId ?? 0), siteData?.id, ['site']);
    useSiteProvisioningUpdates(
        Number(serverId ?? 0),
        siteData?.id,
        isProvisioning,
    );
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isDeploying, setIsDeploying] = useState(false);

    if (!siteData?.id) {
        return (
            <AppLayout breadcrumbs={[]}>
                <Head title="Site" />
                <div className="flex h-full flex-1 flex-col items-center justify-center gap-4 p-4">
                    <p className="text-sm text-muted-foreground">
                        Loading site...
                    </p>
                </div>
            </AppLayout>
        );
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        {
            title: server?.name || siteData.server?.name || 'Server',
            href: teamPath(`/servers/${serverId}`),
        },
        {
            title: siteData.domain,
            href: teamPath(`/servers/${serverId}/sites/${siteData.id}`),
        },
    ];

    const deployments = (siteData.deployments ?? []) as (Deployment & {
        user?: { id: number; name: string };
    })[];

    const handleDelete = () => {
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        setIsDeleting(true);
        router.delete(teamPath(`/servers/${serverId}/sites/${siteData.id}`), {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
            },
        });
    };

    const handleDeploy = () => {
        setIsDeploying(true);
        router.post(
            teamPath(`/servers/${serverId}/sites/${siteData.id}/deployments`),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsDeploying(false);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={
                isProvisioning
                    ? undefined
                    : getSiteSubNavItems(
                          currentTeam?.slug ?? '',
                          String(serverId ?? ''),
                          siteData.id,
                      )
            }
        >
            <Head title={siteData.domain} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {/* Header */}
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
                            <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                                <GitBranchIcon className="h-4 w-4 shrink-0" />
                                <span className="truncate">
                                    {siteData.short_repository}:
                                    {siteData.branch}
                                </span>
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {!isProvisioning && (
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
                        )}
                        {!isProvisioning && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="icon">
                                        <MoreVerticalIcon className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={teamPath(
                                                `/servers/${serverId}/sites/${siteData.id}/edit`,
                                            )}
                                        >
                                            Edit Site
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={handleDelete}
                                        className="text-destructive focus:text-destructive"
                                        disabled={
                                            siteData.status === 'installing'
                                        }
                                    >
                                        <Trash2Icon className="mr-2 h-4 w-4" />
                                        Delete Site
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                {isProvisioning ? (
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                        <ProvisioningStepTimeline
                            heading="Installation progress"
                            waitingDescription="Waiting to start installation."
                            currentStep={siteData.provisioning_step ?? null}
                            steps={siteData.provisioning_steps ?? []}
                        />
                        <Card>
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5 text-sm">
                                <DetailRow
                                    label="Server ID"
                                    value={String(siteData.server?.id ?? '—')}
                                    valueClassName="font-mono"
                                />
                                <DetailRow
                                    label="Site ID"
                                    value={String(siteData.id)}
                                    valueClassName="font-mono"
                                />
                                <DetailRow
                                    label="Framework"
                                    value={siteData.project_type_label ?? '—'}
                                />
                                <DetailRow
                                    label="PHP"
                                    value={`PHP ${siteData.php_version}`}
                                />
                                <DetailRow
                                    label="Public IP"
                                    value={siteData.server?.ip_address ?? '—'}
                                    valueClassName="font-mono"
                                />
                                <p className="border-t pt-4 text-xs text-muted-foreground">
                                    Created{' '}
                                    {format(
                                        new Date(siteData.created_at),
                                        'MMM d, yyyy',
                                    )}
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                        {/* Left column: Deployments, Background processes, Scheduled jobs */}
                        <div className="space-y-6">
                            {/* Deployments */}
                            <div id="deployments" className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <CardTitle>Deployments</CardTitle>
                                    <Button
                                        size="sm"
                                        onClick={handleDeploy}
                                        disabled={
                                            isDeploying ||
                                            siteData.status === 'installing'
                                        }
                                    >
                                        {isDeploying ? (
                                            <>
                                                <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                                Deploying...
                                            </>
                                        ) : (
                                            'Deploy'
                                        )}
                                    </Button>
                                </div>
                                <CardDescription>
                                    Recent deployments for this site.
                                </CardDescription>
                                {deployments.length === 0 ? (
                                    <EmptyState
                                        title="No deployments yet"
                                        description="Deployments will appear here once you trigger your first deployment."
                                        action={
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={teamPath(
                                                        `/servers/${serverId}/sites/${siteData.id}/deployments`,
                                                    )}
                                                >
                                                    Go to Deployments
                                                </Link>
                                            </Button>
                                        }
                                    />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Status
                                                    </TableHead>
                                                    <TableHead>
                                                        Commit
                                                    </TableHead>
                                                    <TableHead>
                                                        Message
                                                    </TableHead>
                                                    <TableHead>
                                                        Deployed
                                                    </TableHead>
                                                    <TableHead className="w-0" />
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {deployments.map((d) => (
                                                    <TableRow key={d.id}>
                                                        <TableCell>
                                                            <StatusBadge
                                                                status={
                                                                    d.status_label
                                                                }
                                                                color={
                                                                    d.status_color
                                                                }
                                                                pulse={
                                                                    d.status ===
                                                                    'running'
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">
                                                            {d.commit_hash
                                                                ? d.commit_hash.slice(
                                                                      0,
                                                                      7,
                                                                  )
                                                                : '—'}
                                                        </TableCell>
                                                        <TableCell className="max-w-[200px] truncate text-sm text-muted-foreground">
                                                            {d.commit_message ??
                                                                '—'}
                                                        </TableCell>
                                                        <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                            {d.finished_at
                                                                ? format(
                                                                      new Date(
                                                                          d.finished_at,
                                                                      ),
                                                                      'MMM d, HH:mm',
                                                                  )
                                                                : d.started_at
                                                                  ? 'Running...'
                                                                  : '—'}
                                                            {d.user?.name && (
                                                                <span className="ml-1">
                                                                    by{' '}
                                                                    {
                                                                        d.user
                                                                            .name
                                                                    }
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
                                                                    href={teamPath(
                                                                        `/servers/${serverId}/sites/${siteData.id}/deployments/${d.id}`,
                                                                    )}
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
                            </div>

                            {/* Background processes */}
                            <div
                                id="background-processes"
                                className="space-y-4"
                            >
                                <CardTitle>Background processes</CardTitle>
                                <CardDescription>
                                    Process managers and workers for this site.
                                </CardDescription>
                                <EmptyState
                                    title="No background processes yet"
                                    description="Add process managers or workers when ready."
                                />
                            </div>

                            {/* Scheduled jobs */}
                            <div id="scheduled-jobs" className="space-y-4">
                                <CardTitle>Scheduled jobs</CardTitle>
                                <CardDescription>
                                    Cron jobs and scheduled tasks for this site.
                                </CardDescription>
                                <EmptyState
                                    title="No scheduled jobs yet"
                                    description="Add cron jobs or scheduled tasks when ready."
                                />
                            </div>
                        </div>

                        {/* Right column: Details sidebar */}
                        <div>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Details</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-5 text-sm">
                                    <div className="space-y-2">
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
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
                                                siteData.project_type_label ??
                                                '—'
                                            }
                                        />
                                        <DetailRow
                                            label="PHP"
                                            value={`PHP ${siteData.php_version}`}
                                        />
                                        <DetailRow
                                            label="Public IP"
                                            value={
                                                siteData.server?.ip_address ??
                                                '—'
                                            }
                                            valueClassName="font-mono"
                                        />
                                    </div>

                                    <div className="space-y-2 border-t pt-4">
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
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
                                            <p className="text-xs text-muted-foreground">
                                                No repository connected.
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2 border-t pt-4">
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
                                            Status
                                        </p>
                                        <p className="text-sm font-medium">
                                            {siteData.status_label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Created{' '}
                                            {format(
                                                new Date(siteData.created_at),
                                                'MMM d, yyyy',
                                            )}
                                        </p>
                                        <Link
                                            href={teamPath(
                                                `/servers/${serverId}`,
                                            )}
                                            className="mt-2 block text-xs font-medium text-muted-foreground hover:text-foreground"
                                        >
                                            View server →
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}
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

function DetailRow({ label, value, valueClassName }: DetailRowProps) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className={`text-sm font-medium ${valueClassName ?? ''}`}>
                {value}
            </span>
        </div>
    );
}
