import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { ProviderCard } from '@/components/provider-accounts/provider-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { ProviderAccount } from '@/types/provider-account';
import { Head, Link, router } from '@inertiajs/react';
import { CloudIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    accounts: {
        data: ProviderAccount[];
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Provider Accounts',
        href: '/provider-accounts',
    },
];

export default function ProviderAccountsIndex({ accounts }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [accountToDelete, setAccountToDelete] = useState<ProviderAccount | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = (account: ProviderAccount) => {
        setAccountToDelete(account);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!accountToDelete) return;

        setIsDeleting(true);
        router.delete(`/provider-accounts/${accountToDelete.id}`, {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setAccountToDelete(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Provider Accounts" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Provider Accounts</h1>
                        <p className="text-muted-foreground text-sm">Connect your cloud provider accounts to provision servers.</p>
                    </div>
                    <Button asChild>
                        <Link href="/provider-accounts/create">
                            <PlusIcon className="mr-2 h-4 w-4" />
                            Connect Provider
                        </Link>
                    </Button>
                </div>

                {accounts.data.length === 0 ? (
                    <EmptyState
                        icon={CloudIcon}
                        title="No provider accounts connected"
                        description="Connect a cloud provider account to start provisioning servers."
                        action={
                            <Button asChild>
                                <Link href="/provider-accounts/create">
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Connect Provider
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {accounts.data.map((account) => (
                            <ProviderCard key={account.id} account={account} onDelete={() => handleDelete(account)} />
                        ))}
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Disconnect Provider Account"
                description={`Are you sure you want to disconnect "${accountToDelete?.name}"? This action cannot be undone.`}
                confirmLabel="Disconnect"
                variant="destructive"
                onConfirm={confirmDelete}
                loading={isDeleting}
            />
        </AppLayout>
    );
}
