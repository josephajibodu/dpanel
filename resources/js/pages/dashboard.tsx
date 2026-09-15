import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { PlusIcon } from 'lucide-react';

import { EmptyState } from '@/components/empty-state';
import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { CardTitle } from '@/components/ui/card';
import { Stat } from '@/components/ui/stat';
import { StatGroup } from '@/components/ui/stat-group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';

interface DashboardDeployment {
    id: number;
    ulid: string;
    status: string;
    status_label: string;
    status_color: 'gray' | 'blue' | 'yellow' | 'green' | 'red' | 'orange';
    commit_message: string | null;
    commit_hash_short: string | null;
    site: {
        id: number;
        ulid: string;
        domain: string;
        server_id: number;
    };
    created_at: string;
}

interface Props {
    stats: {
        servers: number;
        active_servers: number;
        sites: number;
        deployments_this_week: number;
        ssl_alerts: number;
    };
    recentServers: {
        data: Server[];
    };
    recentDeployments: DashboardDeployment[];
}

export default function Dashboard({
    stats,
    recentServers,
    recentDeployments,
}: Props) {
    const teamPath = useTeamPath();
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Dashboard',
            href: teamPath('/dashboard'),
        },
    ];

    const servers = recentServers?.data ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Dashboard
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            An overview of your servers, sites, and deployments.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={teamPath('/servers/create')}>
                            <PlusIcon className="mr-2 h-4 w-4" />
                            Create server
                        </Link>
                    </Button>
                </div>

                {stats.servers === 0 ? (
                    <EmptyState
                        title="No servers yet"
                        description="Create your first server to start deploying sites with FlitOps."
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
                        <StatGroup>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <Stat
                                    bordered
                                    label="Servers"
                                    value={stats.servers}
                                    hint={`${stats.active_servers} active`}
                                />
                                <Stat
                                    bordered
                                    label="Sites"
                                    value={stats.sites}
                                />
                                <Stat
                                    bordered
                                    label="Deployments"
                                    value={stats.deployments_this_week}
                                    hint="last 7 days"
                                />
                                <Stat
                                    bordered
                                    label="SSL alerts"
                                    value={stats.ssl_alerts}
                                    tone={
                                        stats.ssl_alerts > 0
                                            ? 'warning'
                                            : 'default'
                                    }
                                />
                            </div>
                        </StatGroup>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <div className="space-y-4">
                                <CardTitle>Recent servers</CardTitle>
                                {servers.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No servers yet.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Server
                                                    </TableHead>
                                                    <TableHead>Sites</TableHead>
                                                    <TableHead>
                                                        Status
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {servers.map((server) => (
                                                    <TableRow key={server.id}>
                                                        <TableCell>
                                                            <Link
                                                                href={teamPath(
                                                                    `/servers/${server.id}`,
                                                                )}
                                                                className="font-medium hover:underline"
                                                            >
                                                                {server.name}
                                                            </Link>
                                                            <p className="font-mono text-xs text-muted-foreground">
                                                                {server.ip_address ??
                                                                    '—'}
                                                            </p>
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {server.sites_count ??
                                                                0}
                                                        </TableCell>
                                                        <TableCell>
                                                            <ServerStatusBadge
                                                                status={
                                                                    server.status
                                                                }
                                                                statusLabel={
                                                                    server.status_label
                                                                }
                                                                statusColor={
                                                                    server.status_color
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

                            <div className="space-y-4">
                                <CardTitle>Recent deployments</CardTitle>
                                {recentDeployments.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No deployments yet.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Site</TableHead>
                                                    <TableHead>
                                                        Commit
                                                    </TableHead>
                                                    <TableHead>
                                                        Status
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {recentDeployments.map(
                                                    (deployment) => (
                                                        <TableRow
                                                            key={deployment.id}
                                                        >
                                                            <TableCell>
                                                                <Link
                                                                    href={teamPath(
                                                                        `/servers/${deployment.site.server_id}/sites/${deployment.site.id}`,
                                                                    )}
                                                                    className="font-medium hover:underline"
                                                                >
                                                                    {
                                                                        deployment
                                                                            .site
                                                                            .domain
                                                                    }
                                                                </Link>
                                                                <p className="truncate text-xs text-muted-foreground">
                                                                    {deployment.commit_hash_short && (
                                                                        <span className="font-mono">
                                                                            {
                                                                                deployment.commit_hash_short
                                                                            }{' '}
                                                                            ·{' '}
                                                                        </span>
                                                                    )}
                                                                    {formatDistanceToNow(
                                                                        new Date(
                                                                            deployment.created_at,
                                                                        ),
                                                                        {
                                                                            addSuffix: true,
                                                                        },
                                                                    )}
                                                                </p>
                                                            </TableCell>
                                                            <TableCell className="max-w-[200px] truncate text-muted-foreground">
                                                                {deployment.commit_message ??
                                                                    'No commit message'}
                                                            </TableCell>
                                                            <TableCell>
                                                                <StatusBadge
                                                                    status={
                                                                        deployment.status_label
                                                                    }
                                                                    color={
                                                                        deployment.status_color
                                                                    }
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ),
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </div>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
