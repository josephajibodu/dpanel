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
import { StatGroup } from '@/components/ui/stat-group';
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
    const latestDeployment = siteData.latest_deployment;

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
                {isProvisioning && (
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
                )}

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
                    <div className="space-y-6">
                        {/* Overview */}
                        <StatGroup>
                            <div className="rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20">
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                                    <div className="flex items-center gap-3">
                                        <span className="text-base font-semibold">
                                            {siteData.domain}
                                        </span>
                                        <SiteStatusBadge
                                            status={siteData.status}
                                            statusLabel={siteData.status_label}
                                            statusColor={siteData.status_color}
                                        />
                                    </div>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" size="icon">
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
                                                    siteData.status ===
                                                    'installing'
                                                }
                                            >
                                                <Trash2Icon className="mr-2 h-4 w-4" />
                                                Delete Site
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>

                                <div className="space-y-5 p-6">
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Domains
                                        </p>
                                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                                            <a
                                                href={`https://${siteData.domain}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                            >
                                                {siteData.domain}
                                                <ExternalLinkIcon className="h-3.5 w-3.5 text-muted-foreground" />
                                            </a>
                                            {(siteData.aliases ?? []).map(
                                                (alias) => (
                                                    <a
                                                        key={alias}
                                                        href={`https://${alias}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground hover:underline"
                                                    >
                                                        {alias}
                                                        <ExternalLinkIcon className="h-3.5 w-3.5" />
                                                    </a>
                                                ),
                                            )}
                                        </div>
                                        <Link
                                            href={teamPath(
                                                `/servers/${serverId}/sites/${siteData.id}/domains`,
                                            )}
                                            className="mt-3 inline-block text-xs font-medium text-muted-foreground hover:text-foreground"
                                        >
                                            Manage domains →
                                        </Link>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4 border-t pt-5">
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                Status
                                            </p>
                                            <p className="mt-2 text-sm font-medium">
                                                {siteData.status_label}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                Created
                                            </p>
                                            <p className="mt-2 text-sm font-medium">
                                                {format(
                                                    new Date(
                                                        siteData.created_at,
                                                    ),
                                                    'MMM d, yyyy',
                                                )}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="border-t pt-5">
                                        <p className="text-sm text-muted-foreground">
                                            Source
                                        </p>
                                        {siteData.repository ? (
                                            <>
                                                <div className="mt-2 flex items-center gap-1.5 text-sm">
                                                    <GitBranchIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                    <span className="truncate font-mono">
                                                        {
                                                            siteData.short_repository
                                                        }
                                                        :{siteData.branch}
                                                    </span>
                                                </div>
                                                {latestDeployment?.commit_hash && (
                                                    <p className="mt-1 truncate text-xs text-muted-foreground">
                                                        <span className="font-mono">
                                                            {latestDeployment.commit_hash_short ??
                                                                latestDeployment.commit_hash.slice(
                                                                    0,
                                                                    7,
                                                                )}
                                                        </span>
                                                        {latestDeployment.commit_message && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                {
                                                                    latestDeployment.commit_message
                                                                }
                                                            </>
                                                        )}
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                No repository connected.
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid grid-cols-2 gap-4 border-t pt-5">
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                Framework
                                            </p>
                                            <p className="mt-2 text-sm font-medium">
                                                {siteData.project_type_label ??
                                                    '—'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                PHP version
                                            </p>
                                            <p className="mt-2 text-sm font-medium">
                                                PHP {siteData.php_version}
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        className={`grid gap-4 border-t pt-5 ${siteData.repository ? 'grid-cols-2' : 'grid-cols-1'}`}
                                    >
                                        {siteData.repository && (
                                            <div>
                                                <p className="text-sm text-muted-foreground">
                                                    Auto deploy
                                                </p>
                                                <p className="mt-2 text-sm font-medium">
                                                    {siteData.auto_deploy
                                                        ? 'Enabled'
                                                        : 'Disabled'}
                                                </p>
                                            </div>
                                        )}
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                Server
                                            </p>
                                            <div className="mt-2 flex items-center justify-between gap-2">
                                                <span className="text-sm font-medium">
                                                    {siteData.server?.name ??
                                                        server?.name ??
                                                        '—'}
                                                </span>
                                                <Link
                                                    href={teamPath(
                                                        `/servers/${serverId}`,
                                                    )}
                                                    className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                                >
                                                    View →
                                                </Link>
                                            </div>
                                            {siteData.server?.ip_address && (
                                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                    {siteData.server.ip_address}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </StatGroup>

                        {/* Deployments */}
                        <div id="deployments" className="space-y-4">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <CardTitle>Deployments</CardTitle>
                                    <CardDescription>
                                        Recent deployments for this site.
                                    </CardDescription>
                                </div>
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
                                                <TableHead>Status</TableHead>
                                                <TableHead>Commit</TableHead>
                                                <TableHead>Message</TableHead>
                                                <TableHead>Deployed</TableHead>
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
                                                    <TableCell className="font-mono">
                                                        <Link
                                                            href={teamPath(
                                                                `/servers/${serverId}/sites/${siteData.id}/deployments/${d.id}`,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {d.commit_hash
                                                                ? d.commit_hash.slice(
                                                                      0,
                                                                      7,
                                                                  )
                                                                : '—'}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="max-w-[200px] truncate text-muted-foreground">
                                                        {d.commit_message ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell className="whitespace-nowrap text-muted-foreground">
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
                                                                by {d.user.name}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </div>

                        {/* Background processes */}
                        <div id="background-processes" className="space-y-4">
                            <div className="space-y-1">
                                <CardTitle>Background processes</CardTitle>
                                <CardDescription>
                                    Process managers and workers for this site.
                                </CardDescription>
                            </div>
                            <EmptyState
                                title="No background processes yet"
                                description="Add process managers or workers when ready."
                            />
                        </div>

                        {/* Scheduled jobs */}
                        <div id="scheduled-jobs" className="space-y-4">
                            <div className="space-y-1">
                                <CardTitle>Scheduled jobs</CardTitle>
                                <CardDescription>
                                    Cron jobs and scheduled tasks for this site.
                                </CardDescription>
                            </div>
                            <EmptyState
                                title="No scheduled jobs yet"
                                description="Add cron jobs or scheduled tasks when ready."
                            />
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
