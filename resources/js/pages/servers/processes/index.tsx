import { ProcessesPanel } from '@/components/processes/processes-panel';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useServerProcessesUpdates } from '@/hooks/use-server-processes-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { CronJob, ProcessSite, Server, Worker } from '@/types/server';
import { Head, usePage } from '@inertiajs/react';

interface Props {
    server: { data: Server } | Server;
    serverIsReady: boolean;
    workers: { data: Worker[] };
    cronJobs: { data: CronJob[] };
    sites: ProcessSite[];
}

export default function ServerProcessesIndex({
    server: serverProp,
    serverIsReady,
    workers,
    cronJobs,
    sites,
}: Props) {
    const server = serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const teamPath = useTeamPath();
    const { currentTeam } = usePage<SharedData>().props;

    useServerProcessesUpdates(server.id);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Processes', href: teamPath(`/servers/${server.id}/processes`) },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(currentTeam?.slug ?? '', server.id)}
        >
            <Head title={`Processes - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <ProcessesPanel
                    server={server}
                    serverIsReady={serverIsReady}
                    workers={workers?.data ?? []}
                    cronJobs={cronJobs?.data ?? []}
                    sites={sites}
                />
            </div>
        </AppLayout>
    );
}
