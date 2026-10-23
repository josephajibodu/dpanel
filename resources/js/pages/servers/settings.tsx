import { destroy as resetHostKey } from '@/actions/App/Http/Controllers/ServerHostKeyController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Server } from '@/types/server';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { KeyIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    server: { data: Server } | Server;
    ssh_command: string | null;
    has_ssh_key: boolean;
    host_key: { type: string; fingerprint: string } | null;
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
    const [destroyDialogOpen, setDestroyDialogOpen] = useState(false);
    const [isDestroying, setIsDestroying] = useState(false);
    const [resetHostKeyDialogOpen, setResetHostKeyDialogOpen] = useState(false);
    const [isResettingHostKey, setIsResettingHostKey] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Settings', href: teamPath(`/servers/${server.id}/settings`) },
    ];

    const handleDestroy = () => {
        setIsDestroying(true);
        router.delete(teamPath(`/servers/${server.id}`), {
            onFinish: () => {
                setIsDestroying(false);
                setDestroyDialogOpen(false);
            },
        });
    };

    const handleResetHostKey = () => {
        setIsResettingHostKey(true);
        router.delete(
            resetHostKey.url({ team: currentTeam?.slug ?? '', server: server.id }),
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsResettingHostKey(false);
                    setResetHostKeyDialogOpen(false);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(
                currentTeam?.slug ?? '',
                server.id,
            )}
        >
            <Head title={`Settings - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Server Settings
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Manage SSH access and server configuration.
                    </p>
                </div>

                <div className="space-y-6">
                    <div className="space-y-4">
                        <HeadingSmall
                            title="SSH command"
                            description="Copy and paste this command into your terminal to connect to the server."
                        />
                        {has_ssh_key && ssh_command ? (
                            <div className="flex gap-2">
                                <Input
                                    readOnly
                                    value={ssh_command}
                                    className="font-mono"
                                />
                                <CopyButton value={ssh_command} />
                            </div>
                        ) : !server.ip_address ? (
                            <p className="text-sm text-muted-foreground">
                                Server IP not yet assigned. The server may still
                                be provisioning.
                            </p>
                        ) : !can('access_servers') ? (
                            <p className="text-sm text-muted-foreground">
                                Your role doesn&apos;t include SSH access to
                                this team&apos;s servers.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    You need to add and sync an SSH key to
                                    connect. Add an SSH key and sync it to this
                                    server.
                                </p>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={teamPath('/ssh-keys')}>
                                        <KeyIcon className="mr-2 h-4 w-4" />
                                        Add SSH key
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall
                            title="Server identity"
                            description="The fingerprint of the key this server proved its identity with the first time we connected. Every later connection must match it."
                        />
                        {host_key ? (
                            <div className="space-y-4">
                                <dl className="grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
                                    <dt className="text-muted-foreground">
                                        Key type
                                    </dt>
                                    <dd className="font-mono">
                                        {host_key.type}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Fingerprint
                                    </dt>
                                    <dd className="font-mono break-all">
                                        {host_key.fingerprint}
                                    </dd>
                                </dl>
                                {can('manage_servers') && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setResetHostKeyDialogOpen(true)
                                        }
                                    >
                                        Reset server identity
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Not recorded yet. It will be recorded the next
                                time we connect to this server.
                            </p>
                        )}
                    </div>

                    {can('manage_servers') && (
                        <div className="space-y-6">
                            <HeadingSmall
                                title="Destroy server"
                                description="Permanently delete this server and all its data. This cannot be undone."
                            />
                            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                                    <p className="font-medium">Warning</p>
                                    <p className="text-sm">
                                        Please proceed with caution, this cannot
                                        be undone.
                                    </p>
                                </div>
                                <Button
                                    variant="destructive"
                                    onClick={() => setDestroyDialogOpen(true)}
                                >
                                    <Trash2Icon className="mr-2 h-4 w-4" />
                                    Destroy server
                                </Button>
                            </div>
                        </div>
                    )}
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

                <ConfirmDialog
                    open={destroyDialogOpen}
                    onOpenChange={setDestroyDialogOpen}
                    title="Are you sure you want to destroy this server?"
                    description="This will permanently delete the server and all its sites, databases, and data."
                    confirmLabel="Destroy server"
                    variant="destructive"
                    loading={isDestroying}
                    onConfirm={handleDestroy}
                />
            </div>
        </AppLayout>
    );
}
