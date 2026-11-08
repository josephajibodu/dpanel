import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

import { destroy as destroyServer } from '@/actions/App/Http/Controllers/ServerController';
import { destroy as resetHostKey } from '@/actions/App/Http/Controllers/ServerHostKeyController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Server } from '@/types/server';

interface Props {
    server: { data: Server } | Server;
    ssh_command: string | null;
    has_ssh_key: boolean;
    host_key: { type: string; fingerprint: string } | null;
}

function Section({
    title,
    description,
    children,
    tone = 'default',
}: {
    title: string;
    description: string;
    children: ReactNode;
    tone?: 'default' | 'danger';
}) {
    return (
        <section
            className={
                tone === 'danger'
                    ? 'rounded-lg border border-destructive/30 bg-card shadow-md shadow-black/5 dark:shadow-black/20'
                    : 'rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20'
            }
        >
            <div
                className={
                    tone === 'danger'
                        ? 'border-b border-destructive/20 px-6 py-4'
                        : 'border-b px-6 py-4'
                }
            >
                <CardTitle>{title}</CardTitle>
                <CardDescription className="mt-1">
                    {description}
                </CardDescription>
            </div>
            <div className="p-6">{children}</div>
        </section>
    );
}

export default function ServerSettings({
    server: serverProp,
    ssh_command,
    has_ssh_key,
    host_key,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const can = usePermission();
    const server =
        (serverProp as { data?: Server })?.data ?? (serverProp as Server);
    const isCustom = server.provider === 'custom';
    const teamSlug = currentTeam?.slug ?? '';

    const [removeDialogOpen, setRemoveDialogOpen] = useState(false);
    const [isRemoving, setIsRemoving] = useState(false);
    const [resetHostKeyDialogOpen, setResetHostKeyDialogOpen] = useState(false);
    const [isResettingHostKey, setIsResettingHostKey] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Settings', href: teamPath(`/servers/${server.id}/settings`) },
    ];

    const handleRemove = () => {
        setIsRemoving(true);
        router.delete(
            destroyServer.url({ team: teamSlug, server: server.id }),
            {
                onFinish: () => {
                    setIsRemoving(false);
                    setRemoveDialogOpen(false);
                },
            },
        );
    };

    const handleResetHostKey = () => {
        setIsResettingHostKey(true);
        router.delete(resetHostKey.url({ team: teamSlug, server: server.id }), {
            preserveScroll: true,
            onFinish: () => {
                setIsResettingHostKey(false);
                setResetHostKeyDialogOpen(false);
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(teamSlug, server)}
        >
            <Head title={`Settings - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Settings
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        SSH access, identity and lifecycle for {server.name}.
                    </p>
                </div>

                <div className="flex max-w-3xl flex-col gap-6">
                    <Section
                        title="SSH access"
                        description="Connect to this server from your own terminal."
                    >
                        {has_ssh_key && ssh_command ? (
                            <div className="flex items-center gap-2 rounded-md border bg-muted/40 py-1 pr-1 pl-3">
                                <code className="flex-1 truncate font-mono text-sm">
                                    {ssh_command}
                                </code>
                                <CopyButton value={ssh_command} />
                            </div>
                        ) : !server.ip_address ? (
                            <p className="text-sm text-muted-foreground">
                                This server doesn&apos;t have an IP address yet.
                                It may still be provisioning.
                            </p>
                        ) : !can('access_servers') ? (
                            <p className="text-sm text-muted-foreground">
                                Your role doesn&apos;t include SSH access to
                                this team&apos;s servers.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Add an SSH key and sync it to this server to
                                    get a connect command here.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="shrink-0"
                                    asChild
                                >
                                    <Link href={teamPath('/ssh-keys')}>
                                        Add SSH key
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </Section>

                    <Section
                        title="Server identity"
                        description="The key this server proved its identity with the first time we connected. Every later connection must match it."
                    >
                        {host_key ? (
                            <div className="flex flex-col gap-6">
                                <dl className="divide-y rounded-md border text-sm">
                                    <div className="grid gap-1 px-4 py-3 sm:grid-cols-[7rem_1fr] sm:items-center">
                                        <dt className="text-muted-foreground">
                                            Key type
                                        </dt>
                                        <dd className="font-mono">
                                            {host_key.type}
                                        </dd>
                                    </div>
                                    <div className="grid gap-1 px-4 py-2 sm:grid-cols-[7rem_1fr] sm:items-center">
                                        <dt className="text-muted-foreground">
                                            Fingerprint
                                        </dt>
                                        <dd className="flex items-center gap-2">
                                            <span className="flex-1 font-mono break-all">
                                                {host_key.fingerprint}
                                            </span>
                                            <CopyButton
                                                value={host_key.fingerprint}
                                            />
                                        </dd>
                                    </div>
                                </dl>
                                {can('manage_servers') && (
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <p className="text-sm text-muted-foreground">
                                            Rebuilt or reinstalled this server?
                                            Reset so we record its new identity.
                                        </p>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="shrink-0"
                                            onClick={() =>
                                                setResetHostKeyDialogOpen(true)
                                            }
                                        >
                                            Reset identity
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Not recorded yet. We&apos;ll record it the next
                                time we connect to this server.
                            </p>
                        )}
                    </Section>

                    {can('manage_servers') &&
                        (isCustom ? (
                            <Section
                                title="Disconnect server"
                                description="Remove this server from Flitops. The machine and everything running on it stays in place."
                                tone="danger"
                            >
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <ul className="list-disc space-y-1 pl-4 text-sm text-muted-foreground">
                                        <li>
                                            Flitops&apos; SSH key is removed
                                            from the server, so we can no longer
                                            log in.
                                        </li>
                                        <li>
                                            Deploy webhooks, GitHub deploy keys
                                            and free flitops.xyz domains are
                                            removed.
                                        </li>
                                        <li>
                                            Your sites, databases and other SSH
                                            keys stay on the server.
                                        </li>
                                    </ul>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        className="shrink-0"
                                        onClick={() =>
                                            setRemoveDialogOpen(true)
                                        }
                                    >
                                        Disconnect server
                                    </Button>
                                </div>
                            </Section>
                        ) : (
                            <Section
                                title="Destroy server"
                                description={`Delete this server at ${server.provider_label}, along with all its sites, databases and data.`}
                                tone="danger"
                            >
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-sm text-muted-foreground">
                                        This can&apos;t be undone.
                                    </p>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        className="shrink-0"
                                        onClick={() =>
                                            setRemoveDialogOpen(true)
                                        }
                                    >
                                        Destroy server
                                    </Button>
                                </div>
                            </Section>
                        ))}
                </div>

                <ConfirmDialog
                    open={resetHostKeyDialogOpen}
                    onOpenChange={setResetHostKeyDialogOpen}
                    title="Reset this server's identity?"
                    description="Only do this if you rebuilt or reinstalled this server. If you didn't, a changed identity can mean someone is intercepting the connection, so contact your hosting provider before resetting. After the reset, the next successful connection records the server's new identity."
                    confirmLabel="Reset identity"
                    variant="destructive"
                    loading={isResettingHostKey}
                    onConfirm={handleResetHostKey}
                />

                {isCustom ? (
                    <ConfirmDialog
                        open={removeDialogOpen}
                        onOpenChange={setRemoveDialogOpen}
                        title={`Disconnect ${server.name}?`}
                        description="Flitops removes its SSH key from the server and forgets it. Your sites keep running, but git pull on the server stops working and sites on a free flitops.xyz address go offline at that address. If the server can't be reached, its SSH key stays and you'll need to remove the flitops-worker line from authorized_keys yourself."
                        confirmLabel="Disconnect server"
                        variant="destructive"
                        loading={isRemoving}
                        onConfirm={handleRemove}
                    />
                ) : (
                    <ConfirmDialog
                        open={removeDialogOpen}
                        onOpenChange={setRemoveDialogOpen}
                        title={`Destroy ${server.name}?`}
                        description={`This deletes the server at ${server.provider_label} along with all its sites, databases and data. This can't be undone.`}
                        confirmLabel="Destroy server"
                        variant="destructive"
                        loading={isRemoving}
                        onConfirm={handleRemove}
                    />
                )}
            </div>
        </AppLayout>
    );
}
