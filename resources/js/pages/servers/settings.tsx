import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Server } from '@/types/server';
import { Head, Link, router } from '@inertiajs/react';
import { KeyIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    server: { data: Server } | Server;
    ssh_command: string | null;
    has_ssh_key: boolean;
}

export default function ServerSettings({ server: serverProp, ssh_command, has_ssh_key }: Props) {
    const server = (serverProp as { data?: Server })?.data ?? (serverProp as Server);
    const [destroyDialogOpen, setDestroyDialogOpen] = useState(false);
    const [isDestroying, setIsDestroying] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Settings', href: `/servers/${server.id}/settings` },
    ];

    const handleDestroy = () => {
        setIsDestroying(true);
        router.delete(`/servers/${server.id}`, {
            onFinish: () => {
                setIsDestroying(false);
                setDestroyDialogOpen(false);
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(server.id)}
        >
            <Head title={`Settings - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Server Settings
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Manage SSH access and server configuration.
                    </p>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardContent className="pt-6">
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
                                    <p className="text-muted-foreground text-sm">
                                        Server IP not yet assigned. The server may still be provisioning.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        <p className="text-muted-foreground text-sm">
                                            You need to add and sync an SSH key to connect. Add an SSH key and sync it to this server.
                                        </p>
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href="/ssh-keys">
                                                <KeyIcon className="mr-2 h-4 w-4" />
                                                Add SSH key
                                            </Link>
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <HeadingSmall
                            title="Destroy server"
                            description="Permanently delete this server and all its data. This cannot be undone."
                        />
                        <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                            <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                                <p className="font-medium">Warning</p>
                                <p className="text-sm">
                                    Please proceed with caution, this cannot be undone.
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
                </div>

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
