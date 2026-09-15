import { Deferred, Head, Link, useForm, usePage } from '@inertiajs/react';
import { format, formatDistanceToNow } from 'date-fns';
import {
    AlertCircleIcon,
    Loader2Icon,
    PlusIcon,
    RefreshCwIcon,
} from 'lucide-react';

import { DeploymentLog } from '@/components/deployments/deployment-log';
import { EmptyState } from '@/components/empty-state';
import { ProvisioningStepTimeline } from '@/components/provisioning-step-timeline';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Stat } from '@/components/ui/stat';
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
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { type DeploymentLogLine } from '@/hooks/use-deployment-logs';
import { useServerMetrics } from '@/hooks/use-server-metrics';
import { useServerProvisioningLogs } from '@/hooks/use-server-provisioning-logs';
import { useServerProvisioningUpdates } from '@/hooks/use-server-provisioning-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type ServerMetric } from '@/types/metric';
import { Server, ServerDatabase } from '@/types/server';
import { Site } from '@/types/site';

interface Props {
    server: {
        data: Server & {
            sites?: Site[];
            databases?: ServerDatabase[];
            actions?: Array<{
                id: number;
                action: string;
                status: string;
                created_at: string;
            }>;
        };
    };
    provisioningLogs?: Array<{
        type: string;
        message: string;
        created_at: string;
    }>;
    latestMetric?: ServerMetric | null;
}

export default function ServersShow({
    server,
    provisioningLogs,
    latestMetric,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const { server: data, connectionState } = useServerProvisioningUpdates(
        server.data,
    );
    const sites = data.sites ?? [];
    const databases = data.databases ?? [];
    const isProvisioningLifecycle = [
        'pending',
        'creating',
        'provisioning',
    ].includes(data.status);

    const initialLogLines: DeploymentLogLine[] = (provisioningLogs ?? []).map(
        (log) => ({
            type: log.type as DeploymentLogLine['type'],
            message: log.message,
            timestamp: log.created_at,
        }),
    );
    const logs = useServerProvisioningLogs(data.id, initialLogLines);

    const retryForm = useForm({});
    const handleRetryProvisioning = () => {
        retryForm.post(teamPath(`/servers/${data.id}/provision`));
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: data.name, href: teamPath(`/servers/${data.id}`) },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(currentTeam?.slug ?? '', data.id)}
        >
            <Head title={data.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {isProvisioningLifecycle ? (
                    <div className="mx-auto w-full max-w-2xl space-y-6">
                        <ProvisioningStepTimeline
                            heading="Provisioning progress"
                            waitingDescription="Waiting to start provisioning."
                            currentStep={data.provisioning_step ?? null}
                            steps={data.provisioning_steps ?? []}
                        />

                        <div className="rounded-lg border bg-card">
                            <div className="flex items-center gap-2 border-b px-4 py-3">
                                <Loader2Icon className="h-4 w-4 animate-spin text-muted-foreground" />
                                <h2 className="font-medium">
                                    Provisioning log
                                </h2>
                            </div>
                            <DeploymentLog logs={logs} isDeploying />
                        </div>
                    </div>
                ) : (
                    <>
                        {data.status === 'error' && (
                            <div className="space-y-4">
                                <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm dark:border-red-800 dark:bg-red-950">
                                    <AlertCircleIcon className="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" />
                                    <div className="flex-1 space-y-1">
                                        <p className="font-medium text-red-800 dark:text-red-200">
                                            Provisioning failed
                                        </p>
                                        <p className="text-red-700 dark:text-red-300">
                                            {data.error_message ??
                                                'An unexpected error occurred during provisioning.'}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={handleRetryProvisioning}
                                        disabled={retryForm.processing}
                                    >
                                        {retryForm.processing && (
                                            <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                        )}
                                        Retry provisioning
                                    </Button>
                                </div>

                                {logs.length > 0 && (
                                    <div className="rounded-lg border bg-card">
                                        <div className="border-b px-4 py-3">
                                            <h2 className="font-medium">
                                                Provisioning log
                                            </h2>
                                        </div>
                                        <DeploymentLog logs={logs} />
                                    </div>
                                )}
                            </div>
                        )}

                        <Deferred
                            data="latestMetric"
                            fallback={<ServerMetricsOverviewSkeleton />}
                        >
                            <ServerMetricsOverview
                                serverId={data.id}
                                initialMetric={latestMetric ?? null}
                            />
                        </Deferred>

                        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                            {/* Left column: Sites & databases, SSH, events */}
                            <div className="space-y-6">
                                {/* Sites */}
                                <div id="sites" className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <CardTitle>Sites</CardTitle>
                                        {data.status === 'active' && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={teamPath(
                                                        `/servers/${data.id}/sites/create`,
                                                    )}
                                                >
                                                    <PlusIcon className="mr-2 h-4 w-4" />
                                                    New site
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                    <CardDescription>
                                        Websites deployed on this server.
                                    </CardDescription>
                                    {sites.length === 0 ? (
                                        <EmptyState
                                            title="No sites on this server yet"
                                            description="Get started by creating your first site."
                                            action={
                                                data.status === 'active' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={teamPath(
                                                                `/servers/${data.id}/sites/create`,
                                                            )}
                                                        >
                                                            <PlusIcon className="mr-2 h-4 w-4" />
                                                            New site
                                                        </Link>
                                                    </Button>
                                                )
                                            }
                                        />
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>
                                                            Domain
                                                        </TableHead>
                                                        <TableHead>
                                                            Type
                                                        </TableHead>
                                                        <TableHead>
                                                            Status
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {sites.map((site) => (
                                                        <TableRow key={site.id}>
                                                            <TableCell>
                                                                <Link
                                                                    href={teamPath(
                                                                        `/servers/${data.id}/sites/${site.id}`,
                                                                    )}
                                                                    className="font-medium hover:underline"
                                                                >
                                                                    {
                                                                        site.domain
                                                                    }
                                                                </Link>
                                                            </TableCell>
                                                            <TableCell className="text-sm text-muted-foreground">
                                                                {site.project_type_label ??
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell>
                                                                <SiteStatusBadge
                                                                    status={
                                                                        site.status
                                                                    }
                                                                    statusLabel={
                                                                        site.status_label
                                                                    }
                                                                    statusColor={
                                                                        site.status_color
                                                                    }
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </div>

                                {/* Databases */}
                                <div id="databases" className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <CardTitle>Databases</CardTitle>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={teamPath(
                                                    `/servers/${data.id}/databases`,
                                                )}
                                            >
                                                View All
                                            </Link>
                                        </Button>
                                    </div>
                                    <CardDescription>
                                        Manage databases and database users on
                                        this server.
                                    </CardDescription>
                                    {databases.length === 0 ? (
                                        <EmptyState title="No databases on this server yet" />
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>
                                                            Name
                                                        </TableHead>
                                                        <TableHead>
                                                            Charset
                                                        </TableHead>
                                                        <TableHead>
                                                            Status
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {databases.map((db) => (
                                                        <TableRow key={db.id}>
                                                            <TableCell className="font-mono font-medium">
                                                                {db.name}
                                                            </TableCell>
                                                            <TableCell className="text-sm text-muted-foreground">
                                                                {db.charset ??
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell>
                                                                <AutoStatusBadge
                                                                    status={
                                                                        db.status
                                                                    }
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </div>

                                {/* Recent events (optional) */}
                                {data.actions && data.actions.length > 0 && (
                                    <div className="space-y-4">
                                        <div>
                                            <CardTitle>Recent events</CardTitle>
                                            <CardDescription>
                                                Latest provisioning and
                                                management actions.
                                            </CardDescription>
                                        </div>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Action
                                                    </TableHead>
                                                    <TableHead>
                                                        Status
                                                    </TableHead>
                                                    <TableHead>Date</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {data.actions
                                                    .slice(0, 5)
                                                    .map((action) => (
                                                        <TableRow
                                                            key={action.id}
                                                        >
                                                            <TableCell className="font-medium">
                                                                {action.action}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground">
                                                                {action.status}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground">
                                                                {format(
                                                                    new Date(
                                                                        action.created_at,
                                                                    ),
                                                                    'MMM d, yyyy HH:mm',
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </div>

                            {/* Right column: unified sidebar */}
                            <div>
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Server details</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-5 text-sm">
                                        <div className="space-y-2">
                                            <p className="text-xs font-medium text-muted-foreground uppercase">
                                                Server
                                            </p>
                                            <DetailRow
                                                label="Name"
                                                value={data.name}
                                            />
                                            <DetailRow
                                                label="Provider account"
                                                value={
                                                    data.provider_account
                                                        ?.name ??
                                                    data.provider_label
                                                }
                                            />
                                            <DetailRow
                                                label="Size"
                                                value={data.size || '—'}
                                            />
                                            <DetailRow
                                                label="Region"
                                                value={data.region || '—'}
                                            />
                                            <DetailRow
                                                label="SSH port"
                                                value={String(data.ssh_port)}
                                                valueClassName="font-mono"
                                            />
                                        </div>

                                        <div className="space-y-2 border-t pt-4">
                                            <p className="text-xs font-medium text-muted-foreground uppercase">
                                                IP addresses
                                            </p>
                                            <DetailRow
                                                label="Public"
                                                value={
                                                    data.ip_address ||
                                                    'Pending...'
                                                }
                                                valueClassName="font-mono"
                                            />
                                            <DetailRow
                                                label="Private"
                                                value={
                                                    data.private_ip_address ??
                                                    '—'
                                                }
                                                valueClassName="font-mono"
                                            />
                                        </div>

                                        <div className="space-y-2 border-t pt-4">
                                            <p className="text-xs font-medium text-muted-foreground uppercase">
                                                Runtime
                                            </p>
                                            <p className="text-sm font-medium">
                                                PHP {data.php_version} ·{' '}
                                                {data.database_type === 'mysql'
                                                    ? 'MySQL'
                                                    : data.database_type ===
                                                        'postgresql'
                                                      ? 'PostgreSQL'
                                                      : 'MariaDB'}
                                            </p>
                                        </div>

                                        <div className="space-y-2 border-t pt-4">
                                            <p className="text-xs font-medium text-muted-foreground uppercase">
                                                Status
                                            </p>
                                            <p className="text-sm font-medium">
                                                {data.status_label}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Realtime{' '}
                                                {connectionState === 'connected'
                                                    ? 'connected'
                                                    : 'fallback mode'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Created{' '}
                                                {format(
                                                    new Date(data.created_at),
                                                    'MMM d, yyyy',
                                                )}
                                            </p>
                                            {data.provisioned_at && (
                                                <p className="text-xs text-muted-foreground">
                                                    Provisioned{' '}
                                                    {format(
                                                        new Date(
                                                            data.provisioned_at,
                                                        ),
                                                        'MMM d, yyyy',
                                                    )}
                                                </p>
                                            )}
                                            {data.last_ssh_connection_at && (
                                                <p className="text-xs text-muted-foreground">
                                                    Last SSH connection{' '}
                                                    {format(
                                                        new Date(
                                                            data.last_ssh_connection_at,
                                                        ),
                                                        'MMM d, yyyy',
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </>
                )}
            </div>
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

function formatBytes(bytes: number): string {
    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb.toFixed(1)} GB`;
    }

    return `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}

function ServerMetricsOverviewSkeleton() {
    return (
        <section className="space-y-3">
            <div>
                <h2 className="text-base font-semibold">Overview</h2>
                <p className="text-sm text-muted-foreground">
                    Here you can see an overview of your server.
                </p>
            </div>
            <StatGroup>
                <div className="grid gap-2 md:grid-cols-3">
                    {(['CPU load', 'Memory usage', 'Disk usage'] as const).map(
                        (label) => (
                            <div
                                key={label}
                                className="rounded-lg border bg-card p-4 shadow-md shadow-black/5 dark:shadow-black/20"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {label}
                                </p>
                                <Skeleton className="mt-2 h-8 w-16" />
                                <Skeleton className="mt-2 h-3 w-24" />
                            </div>
                        ),
                    )}
                </div>
            </StatGroup>
        </section>
    );
}

interface ServerMetricsOverviewProps {
    serverId: number;
    initialMetric: ServerMetric | null;
}

function ServerMetricsOverview({
    serverId,
    initialMetric,
}: ServerMetricsOverviewProps) {
    const teamPath = useTeamPath();
    const { metric, isRefreshing, refresh } = useServerMetrics(
        serverId,
        initialMetric,
    );

    const memoryPercent = metric
        ? Math.round((metric.memory_used / metric.memory_total) * 100)
        : null;
    const diskPercent = metric
        ? Math.round((metric.disk_used / metric.disk_total) * 100)
        : null;

    const stats = [
        {
            label: 'CPU load',
            value: metric ? metric.load.toFixed(2) : 'N/A',
            hint: metric ? '1-minute load average' : 'No data yet',
        },
        {
            label: 'Memory usage',
            value: memoryPercent !== null ? `${memoryPercent}%` : 'N/A',
            hint: metric
                ? `${formatBytes(metric.memory_used)} / ${formatBytes(metric.memory_total)}`
                : 'No data yet',
        },
        {
            label: 'Disk usage',
            value: diskPercent !== null ? `${diskPercent}%` : 'N/A',
            hint: metric
                ? `${formatBytes(metric.disk_used)} / ${formatBytes(metric.disk_total)}`
                : 'No data yet',
        },
    ];

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <h2 className="text-base font-semibold">Overview</h2>
                    <p className="text-sm text-muted-foreground">
                        Here you can see an overview of your server.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {metric && (
                        <span className="text-xs text-muted-foreground">
                            Updated{' '}
                            {formatDistanceToNow(
                                new Date(metric.collected_at),
                                { addSuffix: true },
                            )}
                        </span>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={refresh}
                        disabled={isRefreshing}
                    >
                        <RefreshCwIcon
                            className={`h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`}
                        />
                        Refresh
                    </Button>
                </div>
            </div>
            <StatGroup>
                <div className="grid gap-2 md:grid-cols-3">
                    {stats.map((stat) => (
                        <Stat
                            key={stat.label}
                            bordered
                            label={stat.label}
                            value={stat.value}
                            hint={stat.hint}
                            action={
                                <Link
                                    href={teamPath(
                                        `/servers/${serverId}/observe`,
                                    )}
                                    className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                >
                                    View
                                </Link>
                            }
                        />
                    ))}
                </div>
            </StatGroup>
        </section>
    );
}
