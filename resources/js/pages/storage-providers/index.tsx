import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { MoreVerticalIcon, RefreshCwIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/status-badge';
import { ConnectStorageDrawer } from '@/components/storage-providers/connect-storage-drawer';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import {
    StorageProvider,
    StorageProviderTypeOption,
} from '@/types/storage-provider';

interface Props {
    storageProviders: {
        data: StorageProvider[];
    };
    types: StorageProviderTypeOption[];
}

const typeIcons: Record<string, string> = {
    cloudflare_r2: '🟧',
    s3: '🪣',
};

export default function StorageProvidersIndex({
    storageProviders,
    types,
}: Props) {
    const teamPath = useTeamPath();
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [providerToDelete, setProviderToDelete] =
        useState<StorageProvider | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Storage Providers', href: teamPath('/storage-providers') },
    ];

    const handleDelete = (provider: StorageProvider) => {
        setProviderToDelete(provider);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!providerToDelete) return;

        setIsDeleting(true);
        router.delete(teamPath(`/storage-providers/${providerToDelete.id}`), {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setProviderToDelete(null);
            },
        });
    };

    const handleValidate = (provider: StorageProvider) => {
        router.post(teamPath(`/storage-providers/${provider.id}/validate`));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Storage Providers" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Storage Providers
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Connect your own object storage for backups and
                            other file storage.
                        </p>
                    </div>
                    <ConnectStorageDrawer types={types} />
                </div>

                {storageProviders.data.length === 0 ? (
                    <EmptyState
                        title="No storage providers connected"
                        description="Connect an object storage account to store backups off-server."
                        action={<ConnectStorageDrawer types={types} />}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Bucket</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Validated</TableHead>
                                <TableHead className="w-[70px]" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {storageProviders.data.map((provider) => (
                                <TableRow key={provider.id}>
                                    <TableCell className="font-medium">
                                        {provider.name}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        <span className="mr-1.5">
                                            {typeIcons[provider.type]}
                                        </span>
                                        {provider.type_label}
                                    </TableCell>
                                    <TableCell className="font-mono text-sm text-muted-foreground">
                                        {provider.bucket ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={
                                                provider.is_valid
                                                    ? 'Connected'
                                                    : 'Invalid'
                                            }
                                            color={
                                                provider.is_valid
                                                    ? 'green'
                                                    : 'red'
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {provider.validated_at
                                            ? format(
                                                  new Date(
                                                      provider.validated_at,
                                                  ),
                                                  'MMM d, yyyy',
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-1">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                    >
                                                        <MoreVerticalIcon className="h-4 w-4" />
                                                        <span className="sr-only">
                                                            Actions
                                                        </span>
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            handleValidate(
                                                                provider,
                                                            )
                                                        }
                                                    >
                                                        <RefreshCwIcon className="mr-2 h-4 w-4" />
                                                        Re-validate Credentials
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            handleDelete(
                                                                provider,
                                                            )
                                                        }
                                                        className="text-destructive focus:text-destructive"
                                                    >
                                                        <Trash2Icon className="mr-2 h-4 w-4" />
                                                        Disconnect
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Disconnect Storage Provider"
                description={`Are you sure you want to disconnect "${providerToDelete?.name}"? This action cannot be undone.`}
                confirmLabel="Disconnect"
                variant="destructive"
                onConfirm={confirmDelete}
                loading={isDeleting}
            />
        </AppLayout>
    );
}
