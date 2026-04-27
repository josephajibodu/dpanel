import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { ClockIcon, GitCommitIcon, RocketIcon, UserIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Deployment } from '@/types/deployment';

interface DeploymentCardProps {
    deployment: Deployment;
    serverId: number;
    siteId: number;
}

export function DeploymentCard({ deployment, serverId, siteId }: DeploymentCardProps) {
    const statusColors = {
        pending: 'border-gray-300 bg-gray-100/80 text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200',
        running: 'border-blue-300 bg-blue-100/80 text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200',
        finished: 'border-green-300 bg-green-100/80 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200',
        failed: 'border-red-300 bg-red-100/80 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
        cancelled: 'border-orange-300 bg-orange-100/80 text-orange-800 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-200',
    };

    return (
        <Card className="px-4 py-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex min-w-0 items-center gap-2">
                        <RocketIcon className="text-muted-foreground h-4 w-4 shrink-0" />
                        <Badge variant="outline" className={`text-sm ${statusColors[deployment.status]}`}>
                            {deployment.status_label}
                        </Badge>
                        <span className="text-muted-foreground truncate text-sm">
                            {deployment.triggered_by === 'manual' ? 'Manual deployment' : `Triggered by ${deployment.triggered_by}`}
                        </span>
                    </div>

                    {deployment.commit_hash && (
                        <div className="flex min-w-0 items-center gap-2 text-sm">
                            <GitCommitIcon className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                            <code className="bg-muted rounded px-2 py-0.5 text-xs">
                                {deployment.commit_hash.slice(0, 7)}
                            </code>
                            {deployment.commit_message && (
                                <span className="text-muted-foreground truncate">
                                    {deployment.commit_message}
                                </span>
                            )}
                        </div>
                    )}

                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        {deployment.commit_author && (
                            <span className="inline-flex items-center gap-1.5">
                                <UserIcon className="h-3.5 w-3.5" />
                                {deployment.commit_author}
                            </span>
                        )}
                        <span className="inline-flex items-center gap-1.5">
                            <ClockIcon className="h-3.5 w-3.5" />
                            {deployment.finished_at
                                ? `Finished ${format(new Date(deployment.finished_at), 'MMM d, yyyy HH:mm')}`
                                : deployment.started_at
                                  ? `Started ${format(new Date(deployment.started_at), 'MMM d, yyyy HH:mm')}`
                                  : `Created ${format(new Date(deployment.created_at), 'MMM d, yyyy HH:mm')}`}
                        </span>
                        {deployment.duration_seconds !== null && (
                            <span>{deployment.duration_seconds}s</span>
                        )}
                    </div>
                </div>

                <Button variant="ghost" size="sm" className="h-8 px-3 text-sm" asChild>
                    <Link href={`/servers/${serverId}/sites/${siteId}/deployments/${deployment.id}`}>
                        View
                    </Link>
                </Button>
            </div>
        </Card>
    );
}
