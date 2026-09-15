import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    MoreVerticalIcon,
    PlusIcon,
    RefreshCwIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/status-badge';
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
import { ProviderAccount } from '@/types/provider-account';

interface Props {
    accounts: {
        data: ProviderAccount[];
    };
}

const providerIcons: Record<string, string> = {
    digitalocean: '🌊',
    hetzner: '🔴',
    vultr: '🦅',
};

export default function ProviderAccountsIndex({ accounts }: Props) {
    const teamPath = useTeamPath();
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Provider Accounts', href: teamPath('/provider-accounts') },
    ];
    const [accountToDelete, setAccountToDelete] =
        useState<ProviderAccount | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = (account: ProviderAccount) => {
        setAccountToDelete(account);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!accountToDelete) return;

        setIsDeleting(true);
        router.delete(teamPath(`/provider-accounts/${accountToDelete.id}`), {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setAccountToDelete(null);
            },
        });
    };

    const handleValidate = (account: ProviderAccount) => {
        router.post(teamPath(`/provider-accounts/${account.id}/validate`));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Provider Accounts" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Provider Accounts
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Connect your cloud provider accounts to provision
                            servers.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={teamPath('/provider-accounts/create')}>
                            <PlusIcon className="mr-2 h-4 w-4" />
                            Connect Provider
                        </Link>
                    </Button>
                </div>

                {accounts.data.length === 0 ? (
                    <EmptyState
                        title="No provider accounts connected"
                        description="Connect a cloud provider account to start provisioning servers."
                        action={
                            <Button asChild>
                                <Link
                                    href={teamPath('/provider-accounts/create')}
                                >
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Connect Provider
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Provider</TableHead>
                                <TableHead className="text-right">
                                    Servers
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Validated</TableHead>
                                <TableHead className="w-[70px]" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.data.map((account) => (
                                <TableRow key={account.id}>
                                    <TableCell>
                                        <Link
                                            href={teamPath(
                                                `/provider-accounts/${account.id}`,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {account.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        <span className="mr-1.5">
                                            {providerIcons[account.provider]}
                                        </span>
                                        {account.provider_label}
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground">
                                        {account.servers_count ?? 0}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={
                                                account.is_valid
                                                    ? 'Connected'
                                                    : 'Invalid'
                                            }
                                            color={
                                                account.is_valid
                                                    ? 'green'
                                                    : 'red'
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {account.validated_at
                                            ? format(
                                                  new Date(
                                                      account.validated_at,
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
                                                                account,
                                                            )
                                                        }
                                                    >
                                                        <RefreshCwIcon className="mr-2 h-4 w-4" />
                                                        Re-validate Credentials
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            handleDelete(
                                                                account,
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
