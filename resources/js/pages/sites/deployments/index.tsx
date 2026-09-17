import { Head, Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    HistoryIcon,
    Loader2Icon,
    MoreVerticalIcon,
    RocketIcon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    getPaginationUrls,
    Pagination,
    type PaginationMeta,
} from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useServerDeploymentUpdates } from '@/hooks/use-server-deployment-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Deployment } from '@/types/deployment';
import { Site } from '@/types/site';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
        };
    };
    deployments: {
        data: Deployment[];
        links?: unknown;
        meta?: PaginationMeta;
    };
}

export default function SiteDeploymentsIndex({
    server: serverProp,
    site: siteProp,
    deployments,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    useServerDeploymentUpdates(Number(serverId ?? 0), site.id, ['deployments']);
    const [isDeploying, setIsDeploying] = useState(false);
    const [rollbackTarget, setRollbackTarget] = useState<Deployment | null>(
        null,
    );
    const [isRollingBack, setIsRollingBack] = useState(false);

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
            title: 'Deployments',
            href: teamPath(`/servers/${serverId}/sites/${site.id}/deployments`),
        },
    ];

    const handleDeploy = () => {
        setIsDeploying(true);
        router.post(
            teamPath(`/servers/${serverId}/sites/${site.id}/deployments`),
            {},
            {
                onFinish: () => {
                    setIsDeploying(false);
                },
            },
        );
    };

    const confirmRollback = () => {
        if (!rollbackTarget) return;
        setIsRollingBack(true);
        router.post(
            teamPath(
                `/servers/${serverId}/sites/${site.id}/deployments/${rollbackTarget.id}/rollback`,
            ),
            {},
            {
                onFinish: () => {
                    setIsRollingBack(false);
                    setRollbackTarget(null);
                },
            },
        );
    };

    const deploymentList = deployments.data ?? [];
    const { prevUrl, nextUrl } = getPaginationUrls(deployments.links);

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(
                currentTeam?.slug ?? '',
                String(serverId ?? ''),
                site.id,
            )}
        >
            <Head title={`Deployments - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Deployment History
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Recent deployments for this site.
                        </p>
                    </div>
                    <Button
                        onClick={handleDeploy}
                        disabled={isDeploying || site.status === 'installing'}
                    >
                        {isDeploying ? (
                            <>
                                <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                Deploying...
                            </>
                        ) : (
                            <>
                                <RocketIcon className="mr-2 h-4 w-4" />
                                Deploy Now
                            </>
                        )}
                    </Button>
                </div>

                {deploymentList.length === 0 ? (
                    <EmptyState
                        title="No deployments yet"
                        description="Deployments will appear here once you trigger your first deployment."
                    />
                ) : (
                    <div className="space-y-4">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Commit</TableHead>
                                    <TableHead>Triggered by</TableHead>
                                    <TableHead>Author</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="w-[70px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {deploymentList.map((deployment) => (
                                    <TableRow key={deployment.id}>
                                        <TableCell>
                                            <StatusBadge
                                                status={deployment.status_label}
                                                color={deployment.status_color}
                                                pulse={
                                                    deployment.status ===
                                                    'running'
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-[280px]">
                                            <div className="flex items-center gap-2">
                                                {deployment.commit_hash && (
                                                    <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                                                        {deployment.commit_hash.slice(
                                                            0,
                                                            7,
                                                        )}
                                                    </span>
                                                )}
                                                <span className="min-w-0 truncate text-muted-foreground">
                                                    {deployment.commit_message ??
                                                        '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {deployment.triggered_by ===
                                            'manual'
                                                ? 'Manual'
                                                : deployment.triggered_by}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {deployment.commit_author ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {deployment.finished_at
                                                ? format(
                                                      new Date(
                                                          deployment.finished_at,
                                                      ),
                                                      'MMM d, yyyy HH:mm',
                                                  )
                                                : deployment.started_at
                                                  ? format(
                                                        new Date(
                                                            deployment.started_at,
                                                        ),
                                                        'MMM d, yyyy HH:mm',
                                                    )
                                                  : format(
                                                        new Date(
                                                            deployment.created_at,
                                                        ),
                                                        'MMM d, yyyy HH:mm',
                                                    )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                        >
                                                            <MoreVerticalIcon className="h-4 w-4" />
                                                            <span className="sr-only">
                                                                Actions
                                                            </span>
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={teamPath(
                                                                    `/servers/${serverId}/sites/${site.id}/deployments/${deployment.id}`,
                                                                )}
                                                            >
                                                                View
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {deployment.rollback_available && (
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    setRollbackTarget(
                                                                        deployment,
                                                                    )
                                                                }
                                                            >
                                                                <HistoryIcon className="mr-2 h-4 w-4" />
                                                                Rollback
                                                            </DropdownMenuItem>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {(prevUrl || nextUrl) && (
                            <Pagination
                                prevUrl={prevUrl}
                                nextUrl={nextUrl}
                                meta={deployments.meta}
                            />
                        )}
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={rollbackTarget !== null}
                onOpenChange={(open) => !open && setRollbackTarget(null)}
                title="Roll back to this deployment?"
                description={`This will point the site back at the code from ${rollbackTarget?.commit_hash ? `commit ${rollbackTarget.commit_hash.slice(0, 7)}` : `deployment #${rollbackTarget?.id}`} without rebuilding it. Database migrations are not reversed.`}
                confirmLabel="Roll back"
                variant="destructive"
                onConfirm={confirmRollback}
                loading={isRollingBack}
            />
        </AppLayout>
    );
}
