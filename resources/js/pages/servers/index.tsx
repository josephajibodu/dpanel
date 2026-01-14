import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { ServerCard } from '@/components/servers/server-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon, ServerIcon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    servers: {
        data: Server[];
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Servers',
        href: '/servers',
    },
];

export default function ServersIndex({ servers }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [serverToDelete, setServerToDelete] = useState<Server | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = (server: Server) => {
        setServerToDelete(server);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!serverToDelete) return;

        setIsDeleting(true);
        router.delete(`/servers/${serverToDelete.id}`, {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setServerToDelete(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Servers</h1>
                        <p className="text-muted-foreground text-sm">Manage your cloud servers and their configurations.</p>
                    </div>
                    <Button asChild>
                        <Link href="/servers/create">
                            <PlusIcon className="mr-2 h-4 w-4" />
                            Create Server
                        </Link>
                    </Button>
                </div>

                {servers.data.length === 0 ? (
                    <EmptyState
                        icon={ServerIcon}
                        title="No servers yet"
                        description="Create your first server to get started with deploying applications."
                        action={
                            <Button asChild>
                                <Link href="/servers/create">
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Create Server
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {servers.data.map((server) => (
                            <ServerCard key={server.id} server={server} onDelete={() => handleDelete(server)} />
                        ))}
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete Server"
                description={`Are you sure you want to delete "${serverToDelete?.name}"? This will permanently destroy the server and all its data.`}
                confirmLabel="Delete Server"
                variant="destructive"
                onConfirm={confirmDelete}
                loading={isDeleting}
            />
        </AppLayout>
    );
}
