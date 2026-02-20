import { DeploymentLog } from '@/components/deployments/deployment-log';
import { Deployment } from '@/types/deployment';
import { Site } from '@/types/site';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { useDeploymentLogs, type DeploymentLogLine } from '@/hooks/use-deployment-logs';
import { useDeploymentUpdates } from '@/hooks/use-deployment-updates';
import { Head } from '@inertiajs/react';
import { getSiteSubNavItems } from '@/config/sub-nav-items';

type SiteData = Site & { server?: { id: number; name: string } };

interface Props {
    deployment: Deployment & {
        site?: Site;
        user?: { id: number; name: string };
    };
    server: { data: { id: number; name: string } };
    site: SiteData | { data: SiteData };
    logs: Array<{
        id: number;
        type: string;
        message: string;
        created_at: string;
    }>;
}

export default function DeploymentsShow({ deployment, server: serverProp, site: siteProp, logs: initialLogs }: Props) {
    const server = serverProp?.data ?? serverProp;
    const site = (siteProp as { data?: SiteData })?.data ?? (siteProp as SiteData);
    const serverId = server?.id ?? site?.server?.id;

    if (!deployment || !site) {
        return (
            <AppLayout breadcrumbs={[]}>
                <div className="flex h-full flex-1 flex-col gap-6 p-4">
                    <div className="text-center">
                        <p className="text-muted-foreground">Loading deployment...</p>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const initialLogLines: DeploymentLogLine[] = (initialLogs || []).map((log) => ({
        id: log.id,
        type: log.type as DeploymentLogLine['type'],
        message: log.message,
        timestamp: log.created_at,
    }));

    const logs = useDeploymentLogs(deployment.id, initialLogLines);
    useDeploymentUpdates(deployment.id);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server?.name || site.server?.name || 'Server', href: `/servers/${serverId}` },
        { title: site.domain, href: `/servers/${serverId}/sites/${site.id}` },
        { title: 'Deployments', href: `/servers/${serverId}/sites/${site.id}/deployments` },
        { title: `#${deployment.id}`, href: `/servers/${serverId}/sites/${site.id}/deployments/${deployment.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs} subNavItems={getSiteSubNavItems(String(serverId ?? ''), site.id)}>
            <Head title={`Deployment #${deployment.id} - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="space-y-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Deployment logs
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Output from this deployment.
                        </p>
                    </div>
                    <DeploymentLog logs={logs} />
                </div>
            </div>
        </AppLayout>
    );
}
