import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { RepositoryProvider, SourceControlAccount } from '@/types/source-control';
import { Head, Link, router } from '@inertiajs/react';
import { CodeIcon, ExternalLinkIcon, MoreVerticalIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    accounts: {
        data: SourceControlAccount[];
    };
    providers: RepositoryProvider[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Source Control',
        href: '/source-control',
    },
];

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

export default function SourceControlIndex({ accounts, providers }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [accountToDelete, setAccountToDelete] = useState<SourceControlAccount | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = (account: SourceControlAccount) => {
        setAccountToDelete(account);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!accountToDelete) return;

        setIsDeleting(true);
        router.delete(`/source-control/${accountToDelete.id}`, {
            onFinish: () => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);
                setAccountToDelete(null);
            },
        });
    };

    const handleConnect = (provider: string) => {
        window.location.href = `/auth/${provider}/redirect?redirect=${encodeURIComponent('/source-control')}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Source Control" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Source Control</h1>
                        <p className="text-muted-foreground text-sm">
                            Connecting to your source control providers allows ServerForge to access your project's codebase, making it
                            possible to deploy your applications.
                        </p>
                    </div>
                    {providers.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button>
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Add Provider
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {providers.map((provider) => {
                                    const existingAccount = accounts.data.find((acc) => acc.provider === provider.value);
                                    return (
                                        <DropdownMenuItem
                                            key={provider.value}
                                            disabled={!!existingAccount}
                                            onClick={() => !existingAccount && handleConnect(provider.value)}
                                        >
                                            <span className="mr-2">{getProviderIcon(provider.value)}</span>
                                            {existingAccount ? `${provider.label} (Connected)` : `Continue with ${provider.label}`}
                                        </DropdownMenuItem>
                                    );
                                })}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>

                {accounts.data.length === 0 ? (
                    <EmptyState
                        icon={CodeIcon}
                        title="No source control providers connected"
                        description="Connect a source control provider to enable deployments from your repositories."
                        action={
                            providers.length > 0 ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button>
                                            <PlusIcon className="mr-2 h-4 w-4" />
                                            Add Provider
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {providers.map((provider) => (
                                            <DropdownMenuItem key={provider.value} onClick={() => handleConnect(provider.value)}>
                                                <span className="mr-2">{getProviderIcon(provider.value)}</span>
                                                Continue with {provider.label}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {accounts.data.map((account) => (
                            <Card key={account.id} className="relative">
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="bg-muted flex h-10 w-10 items-center justify-center rounded-lg text-xl">
                                                {getProviderIcon(account.provider)}
                                            </div>
                                            <div>
                                                <CardTitle className="text-base">{account.provider_label}</CardTitle>
                                                <CardDescription>@{account.provider_username}</CardDescription>
                                            </div>
                                        </div>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="h-8 w-8">
                                                    <MoreVerticalIcon className="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {account.provider === 'github' && (
                                                    <DropdownMenuItem asChild>
                                                        <a href="https://github.com" target="_blank" rel="noopener noreferrer">
                                                            <ExternalLinkIcon className="mr-2 h-4 w-4" />
                                                            Visit GitHub
                                                        </a>
                                                    </DropdownMenuItem>
                                                )}
                                                {account.provider === 'gitlab' && (
                                                    <DropdownMenuItem asChild>
                                                        <a href="https://gitlab.com" target="_blank" rel="noopener noreferrer">
                                                            <ExternalLinkIcon className="mr-2 h-4 w-4" />
                                                            Visit GitLab
                                                        </a>
                                                    </DropdownMenuItem>
                                                )}
                                                {account.provider === 'bitbucket' && (
                                                    <DropdownMenuItem asChild>
                                                        <a href="https://bitbucket.org" target="_blank" rel="noopener noreferrer">
                                                            <ExternalLinkIcon className="mr-2 h-4 w-4" />
                                                            Visit Bitbucket
                                                        </a>
                                                    </DropdownMenuItem>
                                                )}
                                                <DropdownMenuItem onClick={() => handleDelete(account)} className="text-destructive focus:text-destructive">
                                                    <Trash2Icon className="mr-2 h-4 w-4" />
                                                    Disconnect
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground text-sm">Name</span>
                                        <span className="text-sm">{account.name}</span>
                                    </div>
                                    {account.email && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-muted-foreground text-sm">Email</span>
                                            <span className="text-sm">{account.email}</span>
                                        </div>
                                    )}
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground text-sm">Connected</span>
                                        <span className="text-sm">{new Date(account.connected_at).toLocaleDateString()}</span>
                                    </div>
                                    {account.is_token_expired && (
                                        <div className="rounded-lg bg-amber-50 p-2 text-amber-800 dark:bg-amber-900 dark:text-amber-300">
                                            <p className="text-xs font-medium">Token expired - Please reconnect</p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
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
