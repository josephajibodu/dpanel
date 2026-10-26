import { Head, usePage } from '@inertiajs/react';
import { format } from 'date-fns';

import { ServerTerminal } from '@/components/terminal/server-terminal';
import { CardDescription, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { Server } from '@/types/server';

interface TerminalSessionRecord {
    id: number;
    user_name: string | null;
    ip_address: string | null;
    started_at: string | null;
    ended_at: string | null;
}

interface Props {
    server: { data: Server } | Server;
    unavailableReason: string | null;
    sessions: TerminalSessionRecord[];
}

function formatTime(value: string | null): string {
    return value ? format(new Date(value), 'MMM d, yyyy HH:mm') : '—';
}

export default function ServerTerminalIndex({
    server: serverProp,
    unavailableReason,
    sessions,
}: Props) {
    const server =
        serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const teamPath = useTeamPath();
    const { currentTeam } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Terminal', href: teamPath(`/servers/${server.id}/terminal`) },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(currentTeam?.slug ?? '', server)}
        >
            <Head title={`Terminal - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Terminal
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        A live shell on {server.name}, straight from the
                        browser.
                    </p>
                </div>

                {unavailableReason ? (
                    <div className="rounded-lg border bg-card p-6 text-sm text-muted-foreground shadow-md shadow-black/5 dark:shadow-black/20">
                        {unavailableReason}
                    </div>
                ) : (
                    <ServerTerminal serverId={server.id} />
                )}

                <div className="rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20">
                    <div className="border-b px-6 py-4">
                        <CardTitle>Recent sessions</CardTitle>
                        <CardDescription className="mt-1">
                            Who opened a terminal on this server, from where and
                            when. What was typed is not recorded.
                        </CardDescription>
                    </div>
                    {sessions.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            No terminal sessions yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            User
                                        </TableHead>
                                        <TableHead>From</TableHead>
                                        <TableHead>Started</TableHead>
                                        <TableHead>Ended</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sessions.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="pl-6">
                                                {session.user_name ??
                                                    'Removed user'}
                                            </TableCell>
                                            <TableCell className="font-mono text-sm">
                                                {session.ip_address ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {formatTime(session.started_at)}
                                            </TableCell>
                                            <TableCell>
                                                {formatTime(session.ended_at)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
