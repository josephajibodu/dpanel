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

export default function DeploymentsShow({ deployment: deploymentProp, server: serverProp, site: siteProp, logs: initialLogs }: Props) {
    const deployment = (deploymentProp as { data?: Deployment })?.data ?? deploymentProp;
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

    const initialLogLines: DeploymentLogLine[] = (initialLogs ?? []).map((log) => ({
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
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Deployment logs
                            </h1>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Output from this deployment.
                            </p>
                        </div>
                        <StatusBadge status={deployment.status} label={deployment.status_label} />
                    </div>
                    <DeploymentLog logs={logs} />
                </div>
            </div>
        </AppLayout>
    );
}

function StatusBadge({ status, label }: { status: string; label: string }) {
    const statusColors: Record<string, string> = {
        pending: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
        running: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        finished: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        cancelled: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    };
    return (
        <span
            className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${
                statusColors[status] ?? 'bg-gray-100 text-gray-800'
            }`}
        >
            {label}
        </span>
    );
}
