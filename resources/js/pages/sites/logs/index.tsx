import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { ArrowsClockwiseIcon } from '@phosphor-icons/react';
import { useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Site } from '@/types/site';

interface ErrorLog {
    lines: string[];
    failed: boolean;
}

interface Props {
    server: { data: { id: number; name: string } };
    site: { data: Site & { server?: { id: number; name: string } } };
    webServer: 'Nginx' | 'Caddy';
    errorLogAvailable: boolean;
    errorLog?: ErrorLog;
}

export default function SiteLogsIndex({
    server: serverProp,
    site: siteProp,
    webServer,
    errorLogAvailable,
    errorLog,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    const [isRefreshing, setIsRefreshing] = useState(false);

    function handleRefresh() {
        router.reload({
            only: ['errorLog'],
            onStart: () => setIsRefreshing(true),
            onFinish: () => setIsRefreshing(false),
        });
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        {
            title: server?.name || site.server?.name || 'Server',
            href: teamPath(`/servers/${serverId}`),
        },
        {
            title: site.domain,
            href: teamPath(`/servers/${serverId}/sites/${site.id}`),
        },
        {
            title: 'Logs',
            href: teamPath(`/servers/${serverId}/sites/${site.id}/logs`),
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(
                currentTeam?.slug ?? '',
                String(serverId ?? ''),
                site.id,
            )}
        >
            <Head title={`Logs - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Logs
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Recent errors from the web server for {site.domain}.
                    </p>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1.5">
                            <CardTitle>{webServer} error log</CardTitle>
                            <CardDescription>
                                The last 100 lines {webServer} logged for this
                                site, newest at the bottom. Look here first when
                                the site shows a 502 or 500 error.
                            </CardDescription>
                        </div>
                        {errorLogAvailable && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleRefresh}
                                disabled={isRefreshing}
                            >
                                <ArrowsClockwiseIcon
                                    className={`mr-1.5 h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`}
                                />
                                Refresh
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {errorLogAvailable ? (
                            <Deferred
                                data="errorLog"
                                fallback={<ErrorLogSkeleton />}
                            >
                                <ErrorLogLines errorLog={errorLog} />
                            </Deferred>
                        ) : (
                            <EmptyState
                                title={`Not available on ${webServer}`}
                                description={`${webServer} logs errors for every site on the server together, so they can't be shown per site.`}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function ErrorLogLines({ errorLog }: { errorLog?: ErrorLog }) {
    if (errorLog?.failed) {
        return (
            <p className="text-sm text-destructive">
                Couldn't read the error log from the server. Check that the
                server is online, then refresh.
            </p>
        );
    }

    if (!errorLog || errorLog.lines.length === 0) {
        return (
            <EmptyState
                title="No errors logged"
                description="Nothing has been written to this site's error log yet."
            />
        );
    }

    return (
        <pre className="max-h-[600px] overflow-auto rounded-md border bg-muted/40 p-4 font-mono text-xs leading-5 break-all whitespace-pre-wrap">
            {errorLog.lines.join('\n')}
        </pre>
    );
}

function ErrorLogSkeleton() {
    return (
        <div className="space-y-2">
            {Array.from({ length: 6 }).map((_, index) => (
                <Skeleton key={index} className="h-4 w-full animate-pulse" />
            ))}
        </div>
    );
}
