import { DeploymentLog } from '@/components/deployments/deployment-log';
import { Deployment } from '@/types/deployment';
import { Site } from '@/types/site';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { useDeploymentLogs, type DeploymentLogLine } from '@/hooks/use-deployment-logs';
import { useDeploymentUpdates } from '@/hooks/use-deployment-updates';
import { Head, Link, usePage } from '@inertiajs/react';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { formatDistanceToNow } from 'date-fns';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { ClockIcon, GitBranchIcon, Loader2Icon, WrenchIcon, GlobeIcon } from 'lucide-react';

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
    const { flash } = usePage<SharedData>().props;

    const isDeploying = deployment.status === 'pending' || deployment.status === 'running';
    const commitShort = deployment.commit_hash ? deployment.commit_hash.slice(0, 7) : `#${deployment.id}`;

    useEffect(() => {
        const started = flash?.deployment_started;
        if (started) {
            toast.info(`Deploying ${started.commit} on ${started.site}`, {
                icon: <Loader2Icon className="h-4 w-4 animate-spin" />,
            });
        }
    }, [flash?.deployment_started]);

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

    const siteUrl = site.server?.ip_address
        ? `http://${site.domain}`
        : `https://${site.domain}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs} subNavItems={getSiteSubNavItems(String(serverId ?? ''), site.id)}>
            <Head title={`Deployment ${commitShort} - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Deployment details • {commitShort}
                            </h1>
                            <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                                <span className="inline-flex items-center gap-1.5">
                                    {isDeploying ? (
                                        <Loader2Icon className="h-4 w-4 animate-spin" />
                                    ) : null}
                                    <StatusBadge status={deployment.status} label={deployment.status_label} />
                                </span>
                                {deployment.triggered_by === 'manual' && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <WrenchIcon className="h-4 w-4 shrink-0" />
                                        Manual
                                    </span>
                                )}
                                {deployment.commit_message && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <GitBranchIcon className="h-4 w-4 shrink-0" />
                                        {deployment.commit_message}
                                    </span>
                                )}
                                <span className="inline-flex items-center gap-1.5">
                                    <ClockIcon className="h-4 w-4 shrink-0" />
                                    {deployment.created_at
                                        ? formatDistanceToNow(new Date(deployment.created_at), { addSuffix: true })
                                        : 'Just now'}
                                </span>
                            </div>
                        </div>
                        <Link
                            href={siteUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-2 rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground"
                        >
                            <GlobeIcon className="h-4 w-4" />
                            Visit
                        </Link>
                    </div>

                    <div className="rounded-lg border bg-card">
                        <div className="flex items-center gap-2 border-b px-4 py-3">
                            {isDeploying ? (
                                <Loader2Icon className="h-4 w-4 animate-spin text-muted-foreground" />
                            ) : null}
                            <h2 className="font-medium">Build logs</h2>
                        </div>
                        <DeploymentLog logs={logs} isDeploying={isDeploying} />
                    </div>
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
