import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ProviderAccount } from '@/types/provider-account';
import { Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    CloudIcon,
    MoreVerticalIcon,
    RefreshCwIcon,
    ServerIcon,
    Trash2Icon,
} from 'lucide-react';

interface ProviderCardProps {
    account: ProviderAccount;
    onDelete: () => void;
}

const providerIcons: Record<string, string> = {
    digitalocean: '🌊',
    hetzner: '🔴',
    vultr: '🦅',
};

export function ProviderCard({ account, onDelete }: ProviderCardProps) {
    const handleValidate = () => {
        router.post(`/provider-accounts/${account.id}/validate`);
    };

    return (
        <Card className="relative">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-xl">
                            {providerIcons[account.provider] || (
                                <CloudIcon className="h-5 w-5" />
                            )}
                        </div>
                        <div>
                            <CardTitle className="text-base">
                                {account.name}
                            </CardTitle>
                            <CardDescription>
                                {account.provider_label}
                            </CardDescription>
                        </div>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8"
                            >
                                <MoreVerticalIcon className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={handleValidate}>
                                <RefreshCwIcon className="mr-2 h-4 w-4" />
                                Re-validate Credentials
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onClick={onDelete}
                                className="text-destructive focus:text-destructive"
                            >
                                <Trash2Icon className="mr-2 h-4 w-4" />
                                Disconnect
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">
                        Status
                    </span>
                    <StatusBadge
                        status={account.is_valid ? 'Connected' : 'Invalid'}
                        color={account.is_valid ? 'green' : 'red'}
                    />
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">
                        Servers
                    </span>
                    <div className="flex items-center gap-1.5 text-sm font-medium">
                        <ServerIcon className="h-4 w-4" />
                        {account.servers_count ?? 0}
                    </div>
                </div>

                {account.validated_at && (
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-muted-foreground">
                            Validated
                        </span>
                        <span className="text-sm">
                            {format(
                                new Date(account.validated_at),
                                'MMM d, yyyy',
                            )}
                        </span>
                    </div>
                )}

                <div className="pt-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="w-full"
                        asChild
                    >
                        <Link href={`/provider-accounts/${account.id}`}>
                            View Details
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
