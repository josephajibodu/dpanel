import { Head, router, usePage } from '@inertiajs/react';
import { KeyIcon } from 'lucide-react';
import { useState } from 'react';

import { destroy } from '@/actions/App/Http/Controllers/SshKeyController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { AddKeyDialog } from '@/components/ssh-keys/add-key-dialog';
import { SshKeyDetailsDialog } from '@/components/ssh-keys/ssh-key-details-dialog';
import { SshKeyTableRow } from '@/components/ssh-keys/ssh-key-table-row';
import { SyncServersDialog } from '@/components/ssh-keys/sync-servers-dialog';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Server } from '@/types/server';
import { type SshKey } from '@/types/ssh-key';

interface Props {
    sshKeys: {
        data: SshKey[];
    };
    servers?: Server[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'SSH Keys',
        href: '/ssh-keys',
    },
];

export default function SshKeysIndex({ sshKeys: sshKeysData }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [syncDialogOpen, setSyncDialogOpen] = useState(false);
    const [selectedKey, setSelectedKey] = useState<SshKey | null>(null);
    const [detailsKey, setDetailsKey] = useState<SshKey | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Get servers from page props if available (loaded via deferred props)
    const { servers = [] } = usePage<{ servers?: Server[] }>().props;

    const handleDelete = (sshKey: SshKey) => {
        setSelectedKey(sshKey);
        setDeleteDialogOpen(true);
    };

    const handleSync = (sshKey: SshKey) => {
        setSelectedKey(sshKey);
        setSyncDialogOpen(true);
    };

    const handleOpenDetails = (sshKey: SshKey) => {
        setDetailsKey(sshKey);
    };

    const confirmDelete = () => {
        if (!selectedKey) return;

        setIsDeleting(true);
        router.delete(destroy.url(selectedKey.id), {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setSelectedKey(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SSH Keys" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">SSH Keys</h1>
                        <p className="text-muted-foreground text-sm">Manage SSH keys that can be synced to your servers.</p>
                    </div>
                    <AddKeyDialog />
                </div>

                {sshKeysData.data.length === 0 ? (
                    <EmptyState
                        icon={KeyIcon}
                        title="No SSH keys added"
                        description="Add an SSH key to sync it to your servers for secure access."
                        action={<AddKeyDialog />}
                    />
                ) : (
                    <Card>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Servers</TableHead>
                                    <TableHead>Added</TableHead>
                                    <TableHead className="w-[70px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sshKeysData.data.map((sshKey) => (
                                    <SshKeyTableRow
                                        key={sshKey.id}
                                        sshKey={sshKey}
                                        onOpenDetails={() => handleOpenDetails(sshKey)}
                                        onSync={() => handleSync(sshKey)}
                                        onDelete={() => handleDelete(sshKey)}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete SSH Key"
                description={`Are you sure you want to delete "${selectedKey?.name}"? This will also revoke the key from all synced servers.`}
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDelete}
                loading={isDeleting}
            />

            {detailsKey && (
                <SshKeyDetailsDialog
                    key={detailsKey.id}
                    sshKey={detailsKey}
                    onOpenChange={(open) => {
                        if (!open) {
                            setDetailsKey(null);
                        }
                    }}
                />
            )}

            {selectedKey && (
                <SyncServersDialog
                    sshKey={selectedKey}
                    servers={servers}
                    open={syncDialogOpen}
                    onOpenChange={setSyncDialogOpen}
                />
            )}
        </AppLayout>
    );
}
