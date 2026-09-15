import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { ArrowLeftIcon, RefreshCwIcon, ServerIcon } from 'lucide-react';

import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Stat } from '@/components/ui/stat';
import { StatGroup } from '@/components/ui/stat-group';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { ProviderAccount } from '@/types/provider-account';

interface Server {
    id: number;
    ulid: string;
    name: string;
    ip_address: string | null;
    status: string;
    status_label: string;
    status_color: string;
    region: string;
    created_at: string;
}

interface Props {
    account: {
        data: ProviderAccount & {
            servers: Server[];
        };
    };
}

export default function ProviderAccountShow({ account }: Props) {
    const { data } = account;
    const teamPath = useTeamPath();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Provider Accounts', href: teamPath('/provider-accounts') },
        { title: data.name, href: teamPath(`/provider-accounts/${data.id}`) },
    ];

    const handleValidate = () => {
        router.post(teamPath(`/provider-accounts/${data.id}/validate`));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={data.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={teamPath('/provider-accounts')}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {data.name}
                            </h1>
                            <StatusBadge
                                status={data.is_valid ? 'Connected' : 'Invalid'}
                                color={data.is_valid ? 'green' : 'red'}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {data.provider_label}
                        </p>
                    </div>
                    <Button variant="outline" onClick={handleValidate}>
                        <RefreshCwIcon className="mr-2 h-4 w-4" />
                        Re-validate
                    </Button>
                </div>

                <StatGroup>
                    <div className="grid gap-2 md:grid-cols-3">
                        <Stat
                            bordered
                            label="Provider"
                            value={data.provider_label}
                            valueClassName="text-lg"
                        />
                        <Stat
                            bordered
                            label="Servers"
                            value={data.servers_count ?? 0}
                        />
                        <Stat
                            bordered
                            label="Last Validated"
                            value={
                                data.validated_at
                                    ? format(
                                          new Date(data.validated_at),
                                          'MMM d, yyyy HH:mm',
                                      )
                                    : 'Never'
                            }
                            valueClassName="text-lg"
                        />
                    </div>
                </StatGroup>

                <Card>
                    <CardHeader>
                        <CardTitle>Servers</CardTitle>
                        <CardDescription>
                            Servers provisioned using this provider account.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {data.servers && data.servers.length > 0 ? (
                            <div className="space-y-2">
                                {data.servers.map((server) => (
                                    <div
                                        key={server.id}
                                        className="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted/50"
                                    >
                                        <div className="flex items-center gap-3">
                                            <ServerIcon className="h-5 w-5 text-muted-foreground" />
                                            <div>
                                                <p className="font-medium">
                                                    {server.name}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {server.ip_address ||
                                                        'No IP yet'}{' '}
                                                    • {server.region}
                                                </p>
                                            </div>
                                        </div>
                                        <StatusBadge
                                            status={server.status_label}
                                            color={
                                                server.status_color as
                                                    | 'gray'
                                                    | 'blue'
                                                    | 'yellow'
                                                    | 'green'
                                                    | 'red'
                                                    | 'orange'
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState
                                icon={ServerIcon}
                                title="No servers yet"
                                description="Servers provisioned with this account will appear here."
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
