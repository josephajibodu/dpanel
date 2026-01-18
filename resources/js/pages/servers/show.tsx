import { CopyButton } from '@/components/copy-button';
import { RestartDropdown } from '@/components/servers/restart-dropdown';
import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { Site } from '@/types/site';
import { Head, Link } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ArrowLeftIcon,
    CloudIcon,
    DatabaseIcon,
    ExternalLinkIcon,
    GlobeIcon,
    HardDriveIcon,
    PlusIcon,
    ServerIcon,
    TerminalIcon,
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
    const { data } = server;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Servers',
            href: '/servers',
        },
        {
            title: data.name,
            href: `/servers/${data.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={data.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href="/servers">
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {data.name}
                            </h1>
                            <ServerStatusBadge
                                status={data.status}
                                statusLabel={data.status_label}
                                statusColor={data.status_color}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {data.provider_label}
                        </p>
                    </div>
                    <RestartDropdown server={data} />
                </div>

                {/* Status Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>IP Address</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <GlobeIcon className="h-5 w-5" />
                                <span className="font-mono">
                                    {data.ip_address || 'Pending...'}
                                </span>
                                {data.ip_address && (
                                    <CopyButton
                                        value={data.ip_address}
                                        className="h-7 w-7"
                                    />
                                )}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Region</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <CloudIcon className="h-5 w-5" />
                                {data.region}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>PHP Version</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <TerminalIcon className="h-5 w-5" />
                                PHP {data.php_version}
                            </CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Database</CardDescription>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <DatabaseIcon className="h-5 w-5" />
                                {data.database_type === 'mysql'
                                    ? 'MySQL'
                                    : data.database_type === 'postgresql'
                                      ? 'PostgreSQL'
                                      : 'MariaDB'}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Server Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ServerIcon className="h-5 w-5" />
                                Server Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Size
                                </span>
                                <span className="font-medium">{data.size}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    SSH Port
                                </span>
                                <span className="font-mono">
                                    {data.ssh_port}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Provider Account
                                </span>
                                <span>{data.provider_account?.name}</span>
                            </div>
                            {data.provisioned_at && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Provisioned
                                    </span>
                                    <span>
                                        {format(
                                            new Date(data.provisioned_at),
                                            'MMM d, yyyy HH:mm',
                                        )}
                                    </span>
                                </div>
                            )}
                            {data.last_ssh_connection_at && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Last SSH Connection
                                    </span>
                                    <span>
                                        {format(
                                            new Date(
                                                data.last_ssh_connection_at,
                                            ),
                                            'MMM d, yyyy HH:mm',
                                        )}
                                    </span>
                                </div>
                            )}
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Created
                                </span>
                                <span>
                                    {format(
                                        new Date(data.created_at),
                                        'MMM d, yyyy HH:mm',
                                    )}
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Sites */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2">
                                    <HardDriveIcon className="h-5 w-5" />
                                    Sites
                                </CardTitle>
                                {data.status === 'active' && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/servers/${data.id}/sites/create`}
                                        >
                                            <PlusIcon className="mr-2 h-4 w-4" />
                                            Add Site
                                        </Link>
                                    </Button>
                                )}
                            </div>
                            <CardDescription>
                                Websites deployed on this server.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {data.sites && data.sites.length > 0 ? (
                                <div className="space-y-2">
                                    {data.sites.map((site) => (
                                        <Link
                                            key={site.id}
                                            href={`/sites/${site.id}`}
                                            className="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted/50"
                                        >
                                            <div className="flex items-center gap-3">
                                                <GlobeIcon className="h-4 w-4 text-muted-foreground" />
                                                <div>
                                                    <span className="font-medium">
                                                        {site.domain}
                                                    </span>
                                                    {site.project_type_label && (
                                                        <span className="ml-2 text-sm text-muted-foreground">
                                                            (
                                                            {
                                                                site.project_type_label
                                                            }
                                                            )
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <SiteStatusBadge
                                                    status={site.status}
                                                    statusLabel={
                                                        site.status_label
                                                    }
                                                    statusColor={
                                                        site.status_color
                                                    }
                                                />
                                                <ExternalLinkIcon className="h-4 w-4 text-muted-foreground" />
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-8 text-center">
                                    <HardDriveIcon className="mb-2 h-8 w-8 text-muted-foreground" />
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        No sites deployed yet.
                                    </p>
                                    {data.status === 'active' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={`/servers/${data.id}/sites/create`}
                                            >
                                                <PlusIcon className="mr-2 h-4 w-4" />
                                                Add Your First Site
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* SSH Connection Info */}
                {data.status === 'active' && data.ip_address && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TerminalIcon className="h-5 w-5" />
                                SSH Connection
                            </CardTitle>
                            <CardDescription>
                                Connect to your server via SSH.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2 rounded-lg bg-muted p-3 font-mono text-sm">
                                <code>
                                    ssh artisan@{data.ip_address}
                                    {data.ssh_port !== 22 &&
                                        ` -p ${data.ssh_port}`}
                                </code>
                                <CopyButton
                                    value={`ssh artisan@${data.ip_address}${data.ssh_port !== 22 ? ` -p ${data.ssh_port}` : ''}`}
                                    className="ml-auto h-7 w-7"
                                />
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
