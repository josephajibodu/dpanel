import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ExternalLinkIcon,
    MoreVerticalIcon,
    PlusIcon,
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
import {
    RepositoryProvider,
    SourceControlAccount,
} from '@/types/source-control';

interface Props {
    accounts: {
        data: SourceControlAccount[];
    };
    providers: RepositoryProvider[];
}

function getProviderIcon(provider: string) {
    switch (provider) {
        case 'github':
            return '🐙';
        case 'gitlab':
            return '🦊';
        case 'bitbucket':
            return '🔷';
        default:
            return '📦';
    }
}

function getProviderUrl(provider: string) {
    switch (provider) {
        case 'github':
            return 'https://github.com';
        case 'gitlab':
            return 'https://gitlab.com';
        case 'bitbucket':
            return 'https://bitbucket.org';
        default:
            return null;
    }
}

export default function SourceControlIndex({ accounts, providers }: Props) {
    const teamPath = useTeamPath();
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Source Control',
            href: teamPath('/source-control'),
        },
    ];

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [accountToDelete, setAccountToDelete] =
        useState<SourceControlAccount | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = (account: SourceControlAccount) => {
        setAccountToDelete(account);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!accountToDelete) return;

        setIsDeleting(true);
        router.delete(teamPath(`/source-control/${accountToDelete.id}`), {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setAccountToDelete(null);
            },
        });
    };

    const handleConnect = (provider: string) => {
        window.location.href = `/auth/${provider}/redirect?redirect=${encodeURIComponent(teamPath('/source-control'))}`;
    };

    const addProviderMenu = (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button>
                    <PlusIcon className="mr-2 h-4 w-4" />
                    Add Provider
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {providers.map((provider) => {
                    const existingAccount = accounts.data.find(
                        (acc) => acc.provider === provider.value,
                    );
                    return (
                        <DropdownMenuItem
                            key={provider.value}
                            disabled={!!existingAccount}
                            onClick={() =>
                                !existingAccount &&
                                handleConnect(provider.value)
                            }
                        >
                            <span className="mr-2">
                                {getProviderIcon(provider.value)}
                            </span>
                            {existingAccount
                                ? `${provider.label} (Connected)`
                                : `Continue with ${provider.label}`}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Source Control" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Source Control
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Connecting to your source control providers allows
                            your deployment platform to access your project's
                            codebase, making it possible to deploy your
                            applications.
                        </p>
                    </div>
                    {providers.length > 0 && addProviderMenu}
                </div>

                {accounts.data.length === 0 ? (
                    <EmptyState
                        title="No source control providers connected"
                        description="Connect a source control provider to enable deployments from your repositories."
                        action={
                            providers.length > 0 ? addProviderMenu : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Provider</TableHead>
                                <TableHead>Username</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Connected</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-[70px]" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {accounts.data.map((account) => {
                                const externalUrl = getProviderUrl(
                                    account.provider,
                                );
                                return (
                                    <TableRow key={account.id}>
                                        <TableCell className="font-medium">
                                            <span className="mr-1.5">
                                                {getProviderIcon(
                                                    account.provider,
                                                )}
                                            </span>
                                            {account.provider_label}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            @{account.provider_username}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {account.email ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {format(
                                                new Date(account.connected_at),
                                                'MMM d, yyyy',
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {account.is_token_expired ? (
                                                <StatusBadge
                                                    status="Token expired"
                                                    color="red"
                                                />
                                            ) : (
                                                <StatusBadge
                                                    status="Connected"
                                                    color="green"
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-1">
                                                {externalUrl && (
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        asChild
                                                    >
                                                        <a
                                                            href={externalUrl}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label={`Visit ${account.provider_label}`}
                                                        >
                                                            <ExternalLinkIcon className="h-4 w-4" />
                                                        </a>
                                                    </Button>
                                                )}
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
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
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </div>

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Disconnect Source Control Account"
                description={`Are you sure you want to disconnect your ${accountToDelete?.provider_label} account? This action cannot be undone.`}
                confirmLabel="Disconnect"
                variant="destructive"
                onConfirm={confirmDelete}
                loading={isDeleting}
            />
        </AppLayout>
    );
}
