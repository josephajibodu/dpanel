import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { ProvisioningStepTimeline } from '@/components/servers/provisioning-step-timeline';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useServerProvisioningUpdates } from '@/hooks/use-server-provisioning-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Server } from '@/types/server';
import { Site } from '@/types/site';
import { Head, Link, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ActivityIcon,
    ExternalLinkIcon,
    GlobeIcon,
    HardDriveIcon,
    PlusIcon,
    ServerIcon,
} from 'lucide-react';

interface Props {
    server: {
        data: Server & {
            sites?: Site[];
            actions?: Array<{
                id: number;
                action: string;
                status: string;
                created_at: string;
            }>;
        };
    };
}

export default function ServersShow({ server }: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const { server: data, connectionState } = useServerProvisioningUpdates(server.data);
    const sites = data.sites ?? [];
    const isProvisioningLifecycle = ['pending', 'creating', 'provisioning'].includes(data.status);

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
                    <div className="mx-auto w-full max-w-2xl">
                        <ProvisioningStepTimeline server={data} />
                    </div>
                ) : (
                    <>
                        <ServerMetricsOverview serverId={data.id} />

                        <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                            {/* Left column: Sites & databases, SSH, events */}
                            <div className="space-y-6">
                                {/* Sites */}
                                <Card id="sites">
                                    <CardHeader>
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="flex items-center gap-2">
                                                <HardDriveIcon className="h-5 w-5" />
                                                Sites
                                            </CardTitle>
                                            {data.status === 'active' && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={teamPath(`/servers/${data.id}/sites/create`)}>
                                                        <PlusIcon className="mr-2 h-4 w-4" />
                                                        New site
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                        <CardDescription>
                                            Websites deployed on this server.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {sites.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center space-y-3 rounded-lg border border-dashed py-10 text-center">
                                                <HardDriveIcon className="text-muted-foreground h-10 w-10" />
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        No sites on this server yet
                                                    </p>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        Get started by creating your
                                                        first site.
                                                    </p>
                                                </div>
                                                {data.status === 'active' && (
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={teamPath(`/servers/${data.id}/sites/create`)}>
                                                            <PlusIcon className="mr-2 h-4 w-4" />
                                                            New site
                                                        </Link>
                                                    </Button>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>Domain</TableHead>
                                                            <TableHead>Type</TableHead>
                                                            <TableHead>Status</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {sites.map((site) => (
                                                            <TableRow key={site.id}>
                                                                <TableCell>
                                                                    <Link
                                                                        href={teamPath(`/servers/${data.id}/sites/${site.id}`)}
                                                                        className="font-medium hover:underline"
                                                                    >
                                                                        {site.domain}
                                                                    </Link>
                                                                </TableCell>
                                                                <TableCell className="text-sm text-muted-foreground">
                                                                    {site.project_type_label ?? '—'}
                                                                </TableCell>
                                                                <TableCell>
                                                                    <SiteStatusBadge
                                                                        status={site.status}
                                                                        statusLabel={site.status_label}
                                                                        statusColor={site.status_color}
                                                                    />
                                                                </TableCell>
                                                            </TableRow>
                                                        ))}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Databases */}
                                <Card id="databases">
                                    <CardHeader>
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="flex items-center gap-2">
                                                <ServerIcon className="h-5 w-5" />
                                                Databases
                                            </CardTitle>
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={teamPath(`/servers/${data.id}/databases`)}>
                                                    View databases
                                                </Link>
                                            </Button>
                                        </div>
                                        <CardDescription>
                                            Manage databases and database users on this server.
                                        </CardDescription>
                                    </CardHeader>
                                </Card>

                                {/* Recent events (optional) */}
                                {data.actions && data.actions.length > 0 && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <ActivityIcon className="h-5 w-5" />
                                                Recent events
                                            </CardTitle>
                                            <CardDescription>
                                                Latest provisioning and management actions.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-sm">
                                            {data.actions.slice(0, 5).map((action) => (
                                                <div
                                                    key={action.id}
                                                    className="flex items-center justify-between rounded-md border px-3 py-2"
                                                >
                                                    <div className="flex flex-col">
                                                        <span className="font-medium">{action.action}</span>
                                                        <span className="text-muted-foreground text-xs">
                                                            {format(
                                                                new Date(action.created_at),
                                                                'MMM d, yyyy HH:mm',
                                                            )}
                                                        </span>
                                                    </div>
                                                    <span className="text-muted-foreground text-xs">
                                                        {action.status}
                                                    </span>
                                                </div>
                                            ))}
                                        </CardContent>
                                    </Card>
                                )}
                            </div>

                            {/* Right column: unified sidebar */}
                            <div>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <ServerIcon className="h-5 w-5" />
                                            Server details
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-5 text-sm">
                                        <div className="space-y-2">
                                            <p className="text-muted-foreground text-xs font-medium uppercase">
                                                Server
                                            </p>
                                            <DetailRow
                                                label="Name"
                                                value={data.name}
                                            />
                                            <DetailRow
                                                label="Provider account"
                                                value={
                                                    data.provider_account?.name ??
                                                    data.provider_label
                                                }
                                            />
                                            <DetailRow
                                                label="Size"
                                                value={data.size}
                                            />
                                            <DetailRow
                                                label="Region"
                                                value={data.region}
                                            />
                                            <DetailRow
                                                label="SSH port"
                                                value={String(data.ssh_port)}
                                                valueClassName="font-mono"
                                            />
                                        </div>

                                        <div className="space-y-2 border-t pt-4">
                                            <p className="text-muted-foreground text-xs font-medium uppercase">
                                                IP addresses
                                            </p>
                                            <DetailRow
                                                label="Public"
                                                value={
                                                    data.ip_address || 'Pending...'
                                                }
                                                valueClassName="font-mono"
                                            />
                                            <DetailRow
                                                label="Private"
                                                value={
                                                    data.private_ip_address ?? '—'
                                                }
                                                valueClassName="font-mono"
                                            />
                                        </div>

                                        <div className="space-y-2 border-t pt-4">
                                            <p className="text-muted-foreground text-xs font-medium uppercase">
                                                Runtime
                                            </p>
                                            <p className="text-sm font-medium">
                                                PHP {data.php_version} ·{' '}
                                                {data.database_type === 'mysql'
                                                    ? 'MySQL'
                                                    : data.database_type === 'postgresql'
                                                      ? 'PostgreSQL'
                                                      : 'MariaDB'}
                                            </p>
                                        </div>

                                        <div className="space-y-2 border-t pt-4">
                                            <p className="text-muted-foreground text-xs font-medium uppercase">
                                                Status
                                            </p>
                                            <p className="text-sm font-medium">
                                                {data.status_label}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                Realtime {connectionState === 'connected' ? 'connected' : 'fallback mode'}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                Created{' '}
                                                {format(
                                                    new Date(data.created_at),
                                                    'MMM d, yyyy',
                                                )}
                                            </p>
                                            {data.provisioned_at && (
                                                <p className="text-muted-foreground text-xs">
                                                    Provisioned{' '}
                                                    {format(
                                                        new Date(data.provisioned_at),
                                                        'MMM d, yyyy',
                                                    )}
                                                </p>
                                            )}
                                            {data.last_ssh_connection_at && (
                                                <p className="text-muted-foreground text-xs">
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
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className={`text-sm font-medium ${valueClassName ?? ''}`}>
                {value}
            </span>
        </div>
    );
}

interface ServerMetricsOverviewProps {
    serverId: number;
}

function ServerMetricsOverview({ serverId }: ServerMetricsOverviewProps) {
    const teamPath = useTeamPath();

    return (
        <section className="space-y-3">
            <div>
                <h2 className="text-base font-semibold">Overview</h2>
                <p className="text-muted-foreground text-sm">
                    Here you can see an overview of your server.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                <MetricCard
                    label="CPU load"
                    value="N/A"
                    description="No data yet"
                    href={teamPath(`/servers/${serverId}/observe`)}
                />
                <MetricCard
                    label="Memory usage"
                    value="N/A"
                    description="No data yet"
                    href={teamPath(`/servers/${serverId}/observe`)}
                />
                <MetricCard
                    label="Disk usage"
                    value="N/A"
                    description="No data yet"
                    href={teamPath(`/servers/${serverId}/observe`)}
                />
            </div>
        </section>
    );
}

interface MetricCardProps {
    label: string;
    value: string;
    description: string;
    href: string;
}

function MetricCard({ label, value, description, href }: MetricCardProps) {
    return (
        <div className="flex flex-col justify-between rounded-lg border bg-background p-3">
            <div className="flex items-center justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <Link
                    href={href}
                    className="text-muted-foreground hover:text-foreground text-[11px] font-medium"
                >
                    View
                </Link>
            </div>
            <div className="mt-3 text-2xl font-semibold">{value}</div>
            <div className="text-muted-foreground mt-1 text-[11px]">
                {description}
            </div>
        </div>
    );
}

