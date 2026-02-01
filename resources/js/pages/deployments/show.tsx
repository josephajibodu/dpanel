import { DeploymentLog } from '@/components/deployments/deployment-log';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Deployment } from '@/types/deployment';
import { Site } from '@/types/site';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { useDeploymentLogs, type DeploymentLogLine } from '@/hooks/use-deployment-logs';
import { useDeploymentUpdates } from '@/hooks/use-deployment-updates';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ArrowLeftIcon,
    ClockIcon,
    GitCommitIcon,
    Loader2Icon,
    RocketIcon,
    UserIcon,
} from 'lucide-react';

interface Props {
    deployment: Deployment & {
        site?: Site;
        user?: {
            id: number;
            name: string;
        };
    };
    site: Site;
    logs: Array<{
        id: number;
        type: string;
        message: string;
        created_at: string;
    }>;
}

export default function DeploymentsShow({ deployment, site, logs: initialLogs }: Props) {
    // Safety check - ensure we have required data
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

    // Convert initial logs to DeploymentLogLine format
    const initialLogLines: DeploymentLogLine[] = (initialLogs || []).map((log) => ({
        id: log.id,
        type: log.type as DeploymentLogLine['type'],
        message: log.message,
        timestamp: log.created_at,
    }));

    // Use hooks for real-time updates
    const logs = useDeploymentLogs(deployment.id, initialLogLines);
    useDeploymentUpdates(deployment.id);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: site.server?.name || 'Server', href: `/servers/${site.server?.id}` },
        { title: site.domain, href: `/sites/${site.id}` },
        { title: `Deployment #${deployment.id}`, href: `/deployments/${deployment.id}` },
    ];

    const statusColors = {
        pending: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
        running: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        finished: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        cancelled: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    };

    const isRunning = deployment.status === 'running' || deployment.status === 'pending';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Deployment #${deployment.id} - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={`/sites/${site.id}`}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <h1 className="text-2xl font-semibold">Deployment #{deployment.id}</h1>
                        <p className="text-muted-foreground text-sm">{site.domain}</p>
                    </div>
                    <Badge variant="outline" className={statusColors[deployment.status]}>
                        {isRunning && <Loader2Icon className="mr-1 h-3 w-3 animate-spin" />}
                        {deployment.status_label}
                    </Badge>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Main Content - Logs */}
                    <div className="lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Deployment Logs</CardTitle>
                                <CardDescription>
                                    {isRunning
                                        ? 'Deployment is in progress. Logs will appear here in real-time.'
                                        : 'Deployment logs and output.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <DeploymentLog logs={logs} />
                            </CardContent>
                        </Card>
                    </div>

                    {/* Sidebar - Deployment Info */}
                    <div className="space-y-6">
                        {/* Status Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <RocketIcon className="h-5 w-5" />
                                    Status
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Status</span>
                                    <Badge variant="outline" className={statusColors[deployment.status]}>
                                        {deployment.status_label}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Triggered By</span>
                                    <span className="capitalize">{deployment.triggered_by}</span>
                                </div>
                                {deployment.started_at && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Started</span>
                                        <span className="text-sm">
                                            {format(new Date(deployment.started_at), 'MMM d, yyyy HH:mm:ss')}
                                        </span>
                                    </div>
                                )}
                                {deployment.finished_at && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Finished</span>
                                        <span className="text-sm">
                                            {format(new Date(deployment.finished_at), 'MMM d, yyyy HH:mm:ss')}
                                        </span>
                                    </div>
                                )}
                                {deployment.duration_seconds !== null && (
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Duration</span>
                                        <span className="text-sm">{deployment.duration_seconds}s</span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Commit Info */}
                        {deployment.commit_hash && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <GitCommitIcon className="h-5 w-5" />
                                        Commit
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-muted-foreground">Hash</span>
                                        <code className="bg-muted rounded px-2 py-0.5 text-xs">
                                            {deployment.commit_hash.slice(0, 7)}
                                        </code>
                                    </div>
                                    {deployment.commit_message && (
                                        <div>
                                            <span className="text-muted-foreground text-sm">Message</span>
                                            <p className="mt-1 text-sm">{deployment.commit_message}</p>
                                        </div>
                                    )}
                                    {deployment.commit_author && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-muted-foreground">Author</span>
                                            <div className="flex items-center gap-2 text-sm">
                                                <UserIcon className="h-4 w-4" />
                                                <span>{deployment.commit_author}</span>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Site Info */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Site</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Button variant="outline" className="w-full" asChild>
                                    <Link href={`/sites/${site.id}`}>View Site</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
