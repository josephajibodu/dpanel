import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Deployment } from '@/types/deployment';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { ClockIcon, GitCommitIcon, RocketIcon, UserIcon } from 'lucide-react';
import { show } from '@/routes/deployments';

interface DeploymentCardProps {
    deployment: Deployment;
    siteId: number;
}

export function DeploymentCard({ deployment, siteId }: DeploymentCardProps) {
    const statusColors = {
        pending: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
        running: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        finished: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        cancelled: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div
                            className={`flex h-10 w-10 items-center justify-center rounded-full ${
                                deployment.status === 'finished'
                                    ? 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-400'
                                    : deployment.status === 'failed'
                                      ? 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-400'
                                      : deployment.status === 'running'
                                        ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'
                            }`}
                        >
                            <RocketIcon className="h-5 w-5" />
                        </div>
                        <div>
                            <CardTitle className="text-base">
                                <Badge variant="outline" className={statusColors[deployment.status]}>
                                    {deployment.status_label}
                                </Badge>
                            </CardTitle>
                            <CardDescription className="mt-1">
                                {deployment.triggered_by === 'manual' ? 'Manual deployment' : `Triggered by ${deployment.triggered_by}`}
                            </CardDescription>
                        </div>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={show.url({ deployment: deployment.id })}>View Details</Link>
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {deployment.commit_hash && (
                        <div className="flex items-center gap-2 text-sm">
                            <GitCommitIcon className="h-4 w-4 text-muted-foreground" />
                            <code className="bg-muted rounded px-2 py-0.5 text-xs">{deployment.commit_hash.slice(0, 7)}</code>
                            {deployment.commit_message && (
                                <span className="text-muted-foreground truncate">{deployment.commit_message}</span>
                            )}
                        </div>
                    )}

                    {deployment.commit_author && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <UserIcon className="h-4 w-4" />
                            <span>{deployment.commit_author}</span>
                        </div>
                    )}

                    <div className="flex items-center justify-between text-sm">
                        <div className="flex items-center gap-2 text-muted-foreground">
                            <ClockIcon className="h-4 w-4" />
                            {deployment.finished_at ? (
                                <span>Finished {format(new Date(deployment.finished_at), 'MMM d, yyyy HH:mm')}</span>
                            ) : deployment.started_at ? (
                                <span>Started {format(new Date(deployment.started_at), 'MMM d, yyyy HH:mm')}</span>
                            ) : (
                                <span>Created {format(new Date(deployment.created_at), 'MMM d, yyyy HH:mm')}</span>
                            )}
                        </div>
                        {deployment.duration_seconds !== null && (
                            <span className="text-muted-foreground">{deployment.duration_seconds}s</span>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
