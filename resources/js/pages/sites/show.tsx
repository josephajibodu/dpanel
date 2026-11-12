import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowSquareOutIcon,
    CircleNotchIcon,
    DotsThreeVerticalIcon,
    GitBranchIcon,
    TrashIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { format, formatDistanceToNow } from 'date-fns';
import { type ReactNode, useState } from 'react';

import { retry } from '@/actions/App/Http/Controllers/SiteController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { ConnectionInfoDialog } from '@/components/databases/connection-info-dialog';
import { EmptyState } from '@/components/empty-state';
import { PageActions } from '@/components/page-actions';
import { cronFrequencyLabel } from '@/components/processes/processes-panel';
import { ProvisioningStepTimeline } from '@/components/provisioning-step-timeline';
import { FrameworkLabel } from '@/components/sites/framework-icon';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { StatGroup } from '@/components/ui/stat-group';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useRealtimeFallback } from '@/hooks/use-realtime-fallback';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Deployment } from '@/types/deployment';
import type { CronJob, DatabaseConnection, Worker } from '@/types/server';
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
    workers: { data: Worker[] };
    cronJobs: { data: CronJob[] };
    databaseConnection: DatabaseConnection | null;
}

export default function SitesShow({
    server: serverProp,
    site,
    workers,
    cronJobs,
    databaseConnection,
}: Props) {
    const { currentTeam, errors } = usePage<
        SharedData & { errors?: Record<string, string> }
    >().props;
    const teamPath = useTeamPath();
    const can = usePermission();
    const server = serverProp?.data ?? serverProp;
    const siteData = (site?.data ?? site) as Props['site']['data'] | undefined;
    const serverId = server?.id ?? siteData?.server?.id;
    const isProvisioning = ['pending', 'installing'].includes(
        siteData?.status ?? '',
    );
    useRealtimeFallback(['site'], isProvisioning);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isDeploying, setIsDeploying] = useState(false);
    const [isolateDialogOpen, setIsolateDialogOpen] = useState(false);
    const [isIsolating, setIsIsolating] = useState(false);
    const [connectOpen, setConnectOpen] = useState(false);
    const retryForm = useForm({});

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
    const siteWorkers = workers?.data ?? [];
    const siteCronJobs = cronJobs?.data ?? [];

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

    const confirmIsolate = () => {
        setIsIsolating(true);
        router.post(
            teamPath(`/servers/${serverId}/sites/${siteData.id}/isolate`),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsIsolating(false);
                    setIsolateDialogOpen(false);
                },
            },
        );
    };

    const handleRetryCreation = () => {
        retryForm.post(
            retry.url({
                team: currentTeam?.slug ?? '',
                server: Number(serverId),
                site: siteData.id,
            }),
        );
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
                        <StatGroup>
                            <div className="rounded-2xl bg-card shadow-card">
                                <div className="border-b px-6 py-4">
                                    <CardTitle>Details</CardTitle>
                                </div>
                                <div className="space-y-5 p-6 text-sm">
                                    <DetailRow
                                        label="Server ID"
                                        value={String(
                                            siteData.server?.id ?? '—',
                                        )}
                                        valueClassName="font-mono"
                                    />
                                    <DetailRow
                                        label="Site ID"
                                        value={String(siteData.id)}
                                        valueClassName="font-mono"
                                    />
                                    <DetailRow
                                        label="Framework"
                                        value={siteData.framework_label ?? '—'}
                                    />
                                    {siteData.app_directory && (
                                        <DetailRow
                                            label="App folder"
                                            value={siteData.app_directory}
                                            valueClassName="font-mono"
                                        />
                                    )}
                                    <DetailRow
                                        label="PHP"
                                        value={`PHP ${siteData.php_version}`}
                                    />
                                    <DetailRow
                                        label="System user"
                                        value={siteData.system_user}
                                        valueClassName="font-mono"
                                    />
                                    {!siteData.is_isolated &&
                                        can('manage_sites') && (
                                            <div className="space-y-2 border-t pt-4">
                                                <p className="text-xs text-muted-foreground">
                                                    This site shares the server
                                                    user, so if it's hacked it
                                                    can reach your other sites
                                                    and the server.
                                                </p>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setIsolateDialogOpen(
                                                            true,
                                                        )
                                                    }
                                                    disabled={
                                                        ![
                                                            'provisioned',
                                                            'deployed',
                                                        ].includes(
                                                            siteData.status,
                                                        )
                                                    }
                                                >
                                                    Isolate this site
                                                </Button>
                                                {errors?.isolate && (
                                                    <p className="text-xs text-destructive">
                                                        {errors.isolate}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    <DetailRow
                                        label="Public IP"
                                        value={
                                            siteData.server?.ip_address ?? '—'
                                        }
                                        valueClassName="font-mono"
                                    />
                                    <p className="border-t pt-4 text-xs text-muted-foreground">
                                        Created{' '}
                                        {format(
                                            new Date(siteData.created_at),
                                            'MMM d, yyyy',
                                        )}
                                    </p>
                                </div>
                            </div>
                        </StatGroup>
                    </div>
                ) : (
                    <div className="space-y-6">
                        {siteData.status === 'failed' && (
                            <div className="flex items-start gap-3 rounded-xl border border-destructive/20 bg-destructive/[0.06] p-4 text-sm">
                                <WarningCircleIcon className="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" />
                                <div className="flex-1 space-y-1">
                                    <p className="font-medium text-red-800 dark:text-red-200">
                                        Site creation failed
                                    </p>
                                    <p className="text-red-700 dark:text-red-300">
                                        {siteData.error_message ??
                                            'An unexpected error occurred while creating this site.'}
                                    </p>
                                </div>
                                {siteData.creation_failed &&
                                    can('manage_sites') && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={handleRetryCreation}
                                            disabled={retryForm.processing}
                                        >
                                            {retryForm.processing && (
                                                <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                            )}
                                            Retry
                                        </Button>
                                    )}
                            </div>
                        )}

                        {siteData.status !== 'failed' &&
                            siteData.error_message && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                    {siteData.error_message}
                                </div>
                            )}

                        <PageActions>
                            <Button variant="outline" asChild>
                                <a
                                    href={`https://${siteData.domain}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ArrowSquareOutIcon className="h-4 w-4" />
                                    Visit
                                </a>
                            </Button>
                            {can('deploy') && (
                                <Button
                                    onClick={handleDeploy}
                                    disabled={
                                        isDeploying ||
                                        siteData.status === 'installing'
                                    }
                                >
                                    {isDeploying ? (
                                        <>
                                            <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                            Deploying...
                                        </>
                                    ) : (
                                        'Deploy'
                                    )}
                                </Button>
                            )}
                            {can('manage_sites') && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label="More actions"
                                        >
                                            <DotsThreeVerticalIcon
                                                weight="bold"
                                                className="h-4 w-4"
                                            />
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
                                            <TrashIcon className="h-4 w-4" />
                                            Delete Site
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </PageActions>

                        <section className="overflow-hidden rounded-2xl bg-card shadow-card">
                            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-4">
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <h1 className="truncate text-lg font-semibold tracking-tight">
                                        {siteData.domain}
                                    </h1>
                                    <SiteStatusBadge
                                        status={siteData.status}
                                        statusLabel={siteData.status_label}
                                        statusColor={siteData.status_color}
                                    />
                                </div>
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                    {[
                                        siteData.domain,
                                        ...(siteData.aliases ?? []),
                                    ].map((hostname) => (
                                        <a
                                            key={hostname}
                                            href={`https://${hostname}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 text-link hover:underline"
                                        >
                                            {hostname}
                                            <ArrowSquareOutIcon className="size-3.5 shrink-0" />
                                        </a>
                                    ))}
                                    <Link
                                        href={teamPath(
                                            `/servers/${serverId}/sites/${siteData.id}/domains`,
                                        )}
                                        className="text-muted-foreground hover:text-foreground"
                                    >
                                        Manage domains →
                                    </Link>
                                </div>
                            </div>

                            <dl className="grid gap-px border-t bg-border sm:grid-cols-2 lg:grid-cols-4">
                                <OverviewItem
                                    label="Source"
                                    className="sm:col-span-2"
                                    hint={
                                        latestDeployment?.commit_hash && (
                                            <>
                                                <span className="font-mono">
                                                    {latestDeployment.commit_hash_short ??
                                                        latestDeployment.commit_hash.slice(
                                                            0,
                                                            7,
                                                        )}
                                                </span>
                                                {latestDeployment.commit_message &&
                                                    ` · ${latestDeployment.commit_message}`}
                                            </>
                                        )
                                    }
                                >
                                    {siteData.repository ? (
                                        <span className="flex items-center gap-1.5">
                                            <GitBranchIcon className="size-4 shrink-0 text-muted-foreground" />
                                            <span className="truncate font-mono">
                                                {siteData.short_repository}:
                                                {siteData.branch}
                                            </span>
                                        </span>
                                    ) : (
                                        'No repository connected'
                                    )}
                                </OverviewItem>

                                <OverviewItem
                                    label="Uptime"
                                    hint={
                                        siteData.open_incident ? (
                                            <span className="text-destructive-foreground">
                                                Down since{' '}
                                                {format(
                                                    new Date(
                                                        siteData.open_incident
                                                            .started_at,
                                                    ),
                                                    'MMM d, h:mm a',
                                                )}
                                                :{' '}
                                                {siteData.open_incident.reason}
                                            </span>
                                        ) : (
                                            siteData.uptime_checked_at &&
                                            `Checked ${formatDistanceToNow(
                                                new Date(
                                                    siteData.uptime_checked_at,
                                                ),
                                                { addSuffix: true },
                                            )}`
                                        )
                                    }
                                >
                                    {siteData.uptime_status ? (
                                        <StatusBadge
                                            status={
                                                siteData.uptime_status_label ??
                                                ''
                                            }
                                            color={
                                                siteData.uptime_status_color ??
                                                'gray'
                                            }
                                        />
                                    ) : (
                                        'Not checked yet'
                                    )}
                                </OverviewItem>

                                <OverviewItem
                                    label="Framework"
                                    hint={`PHP ${siteData.php_version}`}
                                >
                                    <FrameworkLabel site={siteData} />
                                </OverviewItem>

                                <OverviewItem
                                    label="Server"
                                    hint={
                                        siteData.server?.ip_address && (
                                            <span className="font-mono">
                                                {siteData.server.ip_address}
                                            </span>
                                        )
                                    }
                                >
                                    <Link
                                        href={teamPath(`/servers/${serverId}`)}
                                        className="text-link hover:underline"
                                    >
                                        {siteData.server?.name ??
                                            server?.name ??
                                            '—'}
                                    </Link>
                                </OverviewItem>

                                <OverviewItem
                                    label="Database"
                                    hint={
                                        databaseConnection ? (
                                            <>
                                                On{' '}
                                                {databaseConnection.server.name}
                                                {databaseConnection.database
                                                    .status === 'ready' && (
                                                    <>
                                                        {' · '}
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setConnectOpen(
                                                                    true,
                                                                )
                                                            }
                                                            className="text-link hover:underline"
                                                        >
                                                            Connection details
                                                        </button>
                                                    </>
                                                )}
                                            </>
                                        ) : (
                                            <Link
                                                href={teamPath(
                                                    `/servers/${serverId}/sites/${siteData.id}/database`,
                                                )}
                                                className="text-link hover:underline"
                                            >
                                                Set up a database →
                                            </Link>
                                        )
                                    }
                                >
                                    {databaseConnection ? (
                                        <Link
                                            href={teamPath(
                                                `/servers/${serverId}/sites/${siteData.id}/database`,
                                            )}
                                            className="font-mono text-link hover:underline"
                                        >
                                            {databaseConnection.database.name}
                                        </Link>
                                    ) : (
                                        'Not connected'
                                    )}
                                </OverviewItem>

                                <OverviewItem
                                    label="Auto deploy"
                                    hint={
                                        !siteData.repository
                                            ? 'Needs a repository'
                                            : siteData.auto_deploy
                                              ? `On push to ${siteData.branch}`
                                              : 'Deploys are started by hand'
                                    }
                                >
                                    {siteData.repository && siteData.auto_deploy
                                        ? 'Enabled'
                                        : 'Disabled'}
                                </OverviewItem>

                                <OverviewItem
                                    label="Created"
                                    hint={formatDistanceToNow(
                                        new Date(siteData.created_at),
                                        { addSuffix: true },
                                    )}
                                >
                                    {format(
                                        new Date(siteData.created_at),
                                        'MMM d, yyyy',
                                    )}
                                </OverviewItem>
                            </dl>
                        </section>

                        <div className="flex min-w-0 flex-col gap-8">
                            {/* Deployments */}
                            <div id="deployments" className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <div className="space-y-1">
                                        <CardTitle>Deployments</CardTitle>
                                        <CardDescription>
                                            Recent deployments for this site.
                                        </CardDescription>
                                    </div>
                                </div>
                                {deployments.length === 0 ? (
                                    <EmptyState
                                        title="No deployments yet"
                                        description={
                                            can('deploy')
                                                ? 'Deployments will appear here once you trigger your first deployment.'
                                                : 'Deployments will appear here once the site is deployed.'
                                        }
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
                                                                    by{' '}
                                                                    {
                                                                        d.user
                                                                            .name
                                                                    }
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

                            {/* Workers */}
                            <div id="workers" className="space-y-4">
                                <div className="space-y-1">
                                    <CardTitle>Workers</CardTitle>
                                    <CardDescription>
                                        Supervisor-style workers for this site.
                                    </CardDescription>
                                </div>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Command</TableHead>
                                                <TableHead>User</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="text-right">
                                                    Numprocs
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {siteWorkers.length === 0 ? (
                                                <TableRow>
                                                    <TableCell
                                                        colSpan={5}
                                                        className="border-0 px-0 pt-2 pb-0 group-hover:bg-transparent"
                                                    >
                                                        <EmptyState
                                                            title="No workers yet"
                                                            description={
                                                                can(
                                                                    'manage_processes',
                                                                )
                                                                    ? 'Add workers from the Processes tab.'
                                                                    : 'Workers for this site will appear here.'
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                siteWorkers.map((w) => (
                                                    <TableRow key={w.id}>
                                                        <TableCell className="font-medium">
                                                            {w.name}
                                                        </TableCell>
                                                        <TableCell className="max-w-[200px] truncate font-mono text-xs">
                                                            {w.command}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                                            {w.user}
                                                        </TableCell>
                                                        <TableCell>
                                                            <AutoStatusBadge
                                                                status={
                                                                    w.status
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                                            {w.numprocs}
                                                        </TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>

                            {/* Cron jobs */}
                            <div id="cron-jobs" className="space-y-4">
                                <div className="space-y-1">
                                    <CardTitle>Cron jobs</CardTitle>
                                    <CardDescription>
                                        Scheduled cron jobs for this site.
                                    </CardDescription>
                                </div>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Command</TableHead>
                                                <TableHead>User</TableHead>
                                                <TableHead>Frequency</TableHead>
                                                <TableHead>Enabled</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {siteCronJobs.length === 0 ? (
                                                <TableRow>
                                                    <TableCell
                                                        colSpan={4}
                                                        className="border-0 px-0 pt-2 pb-0 group-hover:bg-transparent"
                                                    >
                                                        <EmptyState
                                                            title="No cron jobs yet"
                                                            description={
                                                                can(
                                                                    'manage_processes',
                                                                )
                                                                    ? 'Add cron jobs from the Processes tab.'
                                                                    : 'Cron jobs for this site will appear here.'
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                siteCronJobs.map((c) => (
                                                    <TableRow key={c.id}>
                                                        <TableCell className="max-w-[200px] truncate font-mono font-medium">
                                                            {c.command}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                                            {c.user}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground">
                                                            {cronFrequencyLabel(
                                                                c.frequency,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {c.status ===
                                                            'deleting' ? (
                                                                <AutoStatusBadge
                                                                    status="deleting"
                                                                    label="Deleting..."
                                                                />
                                                            ) : (
                                                                <AutoStatusBadge
                                                                    status={
                                                                        c.hidden
                                                                            ? 'disabled'
                                                                            : 'enabled'
                                                                    }
                                                                    label={
                                                                        c.hidden
                                                                            ? 'Disabled'
                                                                            : 'Enabled'
                                                                    }
                                                                />
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={isolateDialogOpen}
                onOpenChange={setIsolateDialogOpen}
                title="Isolate this site"
                description={`${siteData.domain} will get its own system user, and its files will move into that user's home folder. The site will be unavailable for a few seconds while this happens. If anything goes wrong, the site is put back exactly as it was.`}
                confirmLabel="Isolate site"
                onConfirm={confirmIsolate}
                loading={isIsolating}
            />

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

            {connectOpen && databaseConnection && (
                <ConnectionInfoDialog
                    server={databaseConnection.server}
                    sshUser={databaseConnection.sshUser}
                    hasSshKey={databaseConnection.hasSshKey}
                    databases={[databaseConnection.database]}
                    databaseUsers={databaseConnection.databaseUsers}
                    initialDatabase={databaseConnection.database.name}
                    teamPath={teamPath}
                    onOpenChange={setConnectOpen}
                />
            )}
        </AppLayout>
    );
}

interface OverviewItemProps {
    label: string;
    /** A smaller, muted second line under the value. */
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}

/** One labelled fact in the grid of the site's overview card. */
function OverviewItem({ label, hint, className, children }: OverviewItemProps) {
    return (
        <div className={cn('min-w-0 bg-card px-5 py-4', className)}>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1 truncate text-sm font-medium">{children}</dd>
            {hint && (
                <dd className="mt-0.5 truncate text-xs text-muted-foreground">
                    {hint}
                </dd>
            )}
        </div>
    );
}

interface DetailRowProps {
    label: string;
    value: ReactNode;
    valueClassName?: string;
}

function DetailRow({ label, value, valueClassName }: DetailRowProps) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="shrink-0 text-sm text-muted-foreground">
                {label}
            </span>
            <span
                className={`min-w-0 truncate text-sm font-medium ${valueClassName ?? ''}`}
            >
                {value}
            </span>
        </div>
    );
}
