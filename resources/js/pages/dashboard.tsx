import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { PlusIcon, ServerIcon } from 'lucide-react';

import { EmptyState } from '@/components/empty-state';
import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
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
                        icon={ServerIcon}
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
                        <Card className="shadow-none">
                            <div className="grid grid-cols-2 divide-y divide-border sm:grid-cols-4 sm:divide-x sm:divide-y-0">
                                <StatCell
                                    label="Servers"
                                    value={stats.servers}
                                    hint={`${stats.active_servers} active`}
                                />
                                <StatCell label="Sites" value={stats.sites} />
                                <StatCell
                                    label="Deployments"
                                    value={stats.deployments_this_week}
                                    hint="last 7 days"
                                />
                                <StatCell
                                    label="SSL alerts"
                                    value={stats.ssl_alerts}
                                    warning={stats.ssl_alerts > 0}
                                />
                            </div>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card className="shadow-none">
                                <CardHeader>
                                    <CardTitle className="text-base font-semibold">
                                        Recent servers
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {servers.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No servers yet.
                                        </p>
                                    ) : (
                                        <ul className="-mx-6 divide-y divide-border">
                                            {servers.map((server) => (
                                                <li
                                                    key={server.id}
                                                    className="flex items-center justify-between gap-3 px-6 py-3"
                                                >
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={teamPath(
                                                                `/servers/${server.id}`,
                                                            )}
                                                            className="truncate text-sm font-medium hover:underline"
                                                        >
                                                            {server.name}
                                                        </Link>
                                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                                            {server.ip_address ??
                                                                '—'}{' '}
                                                            ·{' '}
                                                            {server.sites_count ??
                                                                0}{' '}
                                                            sites
                                                        </p>
                                                    </div>
                                                    <ServerStatusBadge
                                                        status={server.status}
                                                        statusLabel={
                                                            server.status_label
                                                        }
                                                        statusColor={
                                                            server.status_color
                                                        }
                                                    />
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="shadow-none">
                                <CardHeader>
                                    <CardTitle className="text-base font-semibold">
                                        Recent deployments
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {recentDeployments.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No deployments yet.
                                        </p>
                                    ) : (
                                        <ul className="-mx-6 divide-y divide-border">
                                            {recentDeployments.map(
                                                (deployment) => (
                                                    <li
                                                        key={deployment.id}
                                                        className="flex items-center justify-between gap-3 px-6 py-3"
                                                    >
                                                        <div className="min-w-0">
                                                            <Link
                                                                href={teamPath(
                                                                    `/servers/${deployment.site.server_id}/sites/${deployment.site.id}`,
                                                                )}
                                                                className="truncate text-sm font-medium hover:underline"
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
                                                                {deployment.commit_message ??
                                                                    'No commit message'}
                                                                {' · '}
                                                                {formatDistanceToNow(
                                                                    new Date(
                                                                        deployment.created_at,
                                                                    ),
                                                                    {
                                                                        addSuffix: true,
                                                                    },
                                                                )}
                                                            </p>
                                                        </div>
                                                        <StatusBadge
                                                            status={
                                                                deployment.status_label
                                                            }
                                                            color={
                                                                deployment.status_color
                                                            }
                                                        />
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function StatCell({
    label,
    value,
    hint,
    warning = false,
}: {
    label: string;
    value: number;
    hint?: string;
    warning?: boolean;
}) {
    return (
        <div className="px-6 py-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'text-2xl font-semibold',
                    warning && 'text-destructive',
                )}
            >
                {value}
            </p>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}
