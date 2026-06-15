import { ProcessesPanel } from '@/components/processes/processes-panel';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { CronJob, Server, Worker } from '@/types/server';
import type { Site } from '@/types/site';
import { Head, usePage } from '@inertiajs/react';

interface Props {
    server: { data: Server } | Server;
    site: { data: Site } | Site;
    serverIsReady: boolean;
    workers: { data: Worker[] };
    cronJobs: { data: CronJob[] };
}

export default function SiteProcessesIndex({
    server: serverProp,
    site: siteProp,
    serverIsReady,
    workers,
    cronJobs,
}: Props) {
    const server = serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const site = siteProp && 'data' in siteProp ? siteProp.data : siteProp;
    const teamPath = useTeamPath();
    const { currentTeam } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: site.domain, href: teamPath(`/servers/${server.id}/sites/${site.id}`) },
        { title: 'Processes', href: teamPath(`/servers/${server.id}/sites/${site.id}/processes`) },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(currentTeam?.slug ?? '', server.id, site.id)}
        >
            <Head title={`Processes - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <ProcessesPanel
                    server={server}
                    site={{ id: site.id, domain: site.domain }}
                    serverIsReady={serverIsReady}
                    workers={workers?.data ?? []}
                    cronJobs={cronJobs?.data ?? []}
                />
            </div>
        </AppLayout>
    );
}
