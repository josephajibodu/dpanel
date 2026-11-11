import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatGroup } from '@/components/ui/stat-group';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { Server } from '@/types/server';

interface SharedService {
    label: string;
    port: number;
}

interface Connection {
    id: number;
    client_name: string;
    host_address: string;
    client_address: string;
    private_network: boolean;
    status: string;
    status_label: string;
    error_message: string | null;
}

interface AvailableServer {
    id: number;
    name: string;
    private_network: boolean;
}

interface Props {
    server: { data: Server } | Server;
    serverIsReady: boolean;
    services: SharedService[];
    connections: Connection[];
    availableServers: AvailableServer[];
    redisPassword: string | null;
}

export default function ServerConnectionsIndex({
    server: serverProp,
    serverIsReady,
    services,
    connections,
    availableServers,
    redisPassword,
}: Props) {
    const server =
        serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const pageProps = usePage<SharedData>().props;
    const { errors } = pageProps as { errors?: Record<string, string> };
    const teamPath = useTeamPath();
    const canManageConnections = usePermission()('manage_servers');

    const [clientServerId, setClientServerId] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [revoking, setRevoking] = useState<Connection | null>(null);

    const selectedServer = availableServers.find(
        (candidate) => String(candidate.id) === clientServerId,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        {
            title: 'Connections',
            href: teamPath(`/servers/${server.id}/connections`),
        },
    ];

    const handleConnect = (e: React.FormEvent) => {
        e.preventDefault();
        if (!clientServerId) return;
        setIsSubmitting(true);
        router.post(
            teamPath(`/servers/${server.id}/connections`),
            { client_server_id: Number(clientServerId) },
            {
                preserveScroll: true,
                onSuccess: () => setClientServerId(''),
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleRevoke = () => {
        if (!revoking) return;
        router.delete(
            teamPath(`/servers/${server.id}/connections/${revoking.id}`),
            {
                preserveScroll: true,
                onFinish: () => setRevoking(null),
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(
                pageProps.currentTeam?.slug ?? '',
                server,
            )}
        >
            <Head title={`Connections - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Connections
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Let your other servers reach{' '}
                        {services.map((service) => service.label).join(' and ')}{' '}
                        on {server.name}. Only the servers listed here can get
                        through its firewall.
                    </p>
                </div>

                <StatGroup>
                    <div className="rounded-2xl bg-card shadow-card">
                        <div className="border-b px-6 py-4">
                            <CardTitle>Connected servers</CardTitle>
                            <CardDescription className="mt-1">
                                {services
                                    .map(
                                        (service) =>
                                            `${service.label} on port ${service.port}`,
                                    )
                                    .join(', ')}
                                .
                            </CardDescription>
                        </div>

                        <div className="flex flex-col gap-4 p-6">
                            {connections.length === 0 ? (
                                <EmptyState
                                    title="No servers connected yet"
                                    description="Connect a server to let its sites and workers use this server's services."
                                />
                            ) : (
                                <ul className="divide-y rounded-md border">
                                    {connections.map((connection) => (
                                        <li
                                            key={connection.id}
                                            className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="font-medium">
                                                        {connection.client_name}
                                                    </p>
                                                    <AutoStatusBadge
                                                        status={
                                                            connection.status
                                                        }
                                                        label={
                                                            connection.status_label
                                                        }
                                                    />
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    Connects to{' '}
                                                    <span className="font-mono">
                                                        {
                                                            connection.host_address
                                                        }
                                                    </span>{' '}
                                                    from{' '}
                                                    <span className="font-mono">
                                                        {
                                                            connection.client_address
                                                        }
                                                    </span>
                                                    {connection.private_network
                                                        ? ' over the private network.'
                                                        : ' over the public internet.'}
                                                </p>
                                                {connection.error_message && (
                                                    <p className="text-sm text-destructive">
                                                        {
                                                            connection.error_message
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            {canManageConnections && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={
                                                        connection.status ===
                                                        'removing'
                                                    }
                                                    onClick={() =>
                                                        setRevoking(connection)
                                                    }
                                                >
                                                    Disconnect
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {canManageConnections && (
                                <form
                                    onSubmit={handleConnect}
                                    className="flex flex-col gap-2"
                                >
                                    <Label htmlFor="client-server">
                                        Connect a server
                                    </Label>
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <Select
                                            value={clientServerId}
                                            onValueChange={setClientServerId}
                                            disabled={
                                                !serverIsReady ||
                                                availableServers.length === 0
                                            }
                                        >
                                            <SelectTrigger
                                                id="client-server"
                                                className="sm:w-64"
                                            >
                                                <SelectValue placeholder="Select a server" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availableServers.map(
                                                    (candidate) => (
                                                        <SelectItem
                                                            key={candidate.id}
                                                            value={String(
                                                                candidate.id,
                                                            )}
                                                        >
                                                            {candidate.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <Button
                                            type="submit"
                                            disabled={
                                                !serverIsReady ||
                                                isSubmitting ||
                                                !clientServerId
                                            }
                                        >
                                            Connect
                                        </Button>
                                    </div>
                                    {errors?.client_server_id && (
                                        <p className="text-sm text-destructive">
                                            {errors.client_server_id}
                                        </p>
                                    )}
                                    {selectedServer &&
                                        !selectedServer.private_network && (
                                            <p className="text-sm text-amber-600 dark:text-amber-400">
                                                {selectedServer.name} isn't on
                                                the same private network, so
                                                traffic goes over the public
                                                internet. Only its address is
                                                let through the firewall.
                                            </p>
                                        )}
                                    {availableServers.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            All your other active servers are
                                            already connected.
                                        </p>
                                    )}
                                </form>
                            )}
                        </div>
                    </div>

                    {redisPassword && (
                        <div className="rounded-2xl bg-card shadow-card">
                            <div className="border-b px-6 py-4">
                                <CardTitle>Redis password</CardTitle>
                                <CardDescription className="mt-1">
                                    Set this as{' '}
                                    <span className="font-mono">
                                        REDIS_PASSWORD
                                    </span>{' '}
                                    on connected sites, with the address above
                                    as{' '}
                                    <span className="font-mono">
                                        REDIS_HOST
                                    </span>
                                    .
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2 p-6">
                                <code className="truncate rounded bg-muted px-2 py-1 font-mono text-sm">
                                    {redisPassword}
                                </code>
                                <CopyButton value={redisPassword} />
                            </div>
                        </div>
                    )}
                </StatGroup>
            </div>

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title={`Disconnect ${revoking?.client_name ?? ''}?`}
                description="Its sites and workers will no longer be able to reach this server's services."
                confirmLabel="Disconnect"
                variant="destructive"
                onConfirm={handleRevoke}
            />
        </AppLayout>
    );
}
