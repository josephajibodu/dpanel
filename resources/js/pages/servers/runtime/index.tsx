import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { updateNodeVersion } from '@/actions/App/Http/Controllers/ServerRuntimeController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import { StatGroup } from '@/components/ui/stat-group';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { Server } from '@/types/server';

interface NodeRuntime {
    version: string;
    label: string;
    is_end_of_life: boolean;
    status: string;
}

interface NodeVersionOption {
    value: string;
    label: string;
    is_end_of_life: boolean;
}

interface Props {
    server: { data: Server } | Server;
    serverIsReady: boolean;
    node: NodeRuntime;
    availableNodeVersions: NodeVersionOption[];
}

export default function ServerRuntimeIndex({
    server: serverProp,
    serverIsReady,
    node,
    availableNodeVersions,
}: Props) {
    const server =
        serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const pageProps = usePage<SharedData>().props;
    const { errors } = pageProps as { errors?: Record<string, string> };
    const teamPath = useTeamPath();
    const canManageRuntime = usePermission()('manage_php');

    const [versionToSwitchTo, setVersionToSwitchTo] =
        useState<NodeVersionOption | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const isSwitching =
        node.status === 'pending' || node.status === 'installing';
    const hasFailed = node.status === 'failed';
    const isReinstall = versionToSwitchTo?.value === node.version;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Runtime', href: teamPath(`/servers/${server.id}/runtime`) },
    ];

    const handleSwitchVersion = () => {
        if (!versionToSwitchTo) {
            return;
        }

        setIsSubmitting(true);
        router.put(
            updateNodeVersion.url({
                team: pageProps.currentTeam?.slug ?? '',
                server: server.id,
            }),
            { version: versionToSwitchTo.value },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsSubmitting(false);
                    setVersionToSwitchTo(null);
                },
            },
        );
    };

    const switchDescription = (target: NodeVersionOption | null): string => {
        if (!target) {
            return '';
        }

        if (target.value === node.version) {
            return `This installs ${target.label} on ${server.name} again, to repair the failed switch. Sites use it from their next deploy.`;
        }

        const endOfLifeWarning = target.is_end_of_life
            ? `${target.label} no longer gets security updates. `
            : '';

        return `${endOfLifeWarning}Sites on ${server.name} start using ${target.label} from their next deploy. Processes already running stay on ${node.label} until they restart.`;
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(
                pageProps.currentTeam?.slug ?? '',
                server,
            )}
        >
            <Head title={`Runtime - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Runtime
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Language runtimes installed on {server.name}.
                    </p>
                </div>

                <StatGroup>
                    <div className="rounded-2xl bg-card shadow-card">
                        <div className="border-b px-6 py-4">
                            <div className="flex items-center justify-between gap-4">
                                <CardTitle>Node.js</CardTitle>
                                {isSwitching && (
                                    <AutoStatusBadge
                                        status={node.status}
                                        label="Switching"
                                    />
                                )}
                                {hasFailed && (
                                    <AutoStatusBadge status="failed" />
                                )}
                            </div>
                            <CardDescription className="mt-1">
                                Every site on this server uses this version when
                                a deploy runs{' '}
                                <span className="font-mono">npm</span> commands,
                                such as building front-end assets.
                            </CardDescription>
                        </div>
                        <div className="flex flex-col gap-4 p-6">
                            {hasFailed && (
                                <p className="text-sm text-destructive">
                                    The last switch failed, so the server may
                                    still be on {node.label}. Try again.
                                </p>
                            )}
                            {node.is_end_of_life && !hasFailed && (
                                <p className="text-sm text-destructive">
                                    {node.label} no longer gets security
                                    updates. Switch to a newer version.
                                </p>
                            )}
                            {canManageRuntime && !serverIsReady && (
                                <p className="text-sm text-muted-foreground">
                                    You can switch versions once the server is
                                    active and connected.
                                </p>
                            )}

                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Version</TableHead>
                                        <TableHead>Status</TableHead>
                                        {canManageRuntime && (
                                            <TableHead className="w-[120px]" />
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {availableNodeVersions.map((version) => {
                                        const isCurrent =
                                            version.value === node.version;

                                        return (
                                            <TableRow key={version.value}>
                                                <TableCell className="font-medium">
                                                    {version.label}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {isCurrent && (
                                                            <StatusBadge
                                                                status="Current"
                                                                color="green"
                                                            />
                                                        )}
                                                        {version.is_end_of_life && (
                                                            <StatusBadge
                                                                status="End of life"
                                                                color="orange"
                                                                className="normal-case"
                                                            />
                                                        )}
                                                        {!isCurrent &&
                                                            !version.is_end_of_life && (
                                                                <span className="text-sm text-muted-foreground">
                                                                    —
                                                                </span>
                                                            )}
                                                    </div>
                                                </TableCell>
                                                {canManageRuntime && (
                                                    <TableCell className="text-right">
                                                        {(!isCurrent ||
                                                            hasFailed) && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    !serverIsReady ||
                                                                    isSwitching ||
                                                                    isSubmitting
                                                                }
                                                                onClick={() =>
                                                                    setVersionToSwitchTo(
                                                                        version,
                                                                    )
                                                                }
                                                            >
                                                                {isCurrent
                                                                    ? 'Reinstall'
                                                                    : 'Switch'}
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>

                            {errors?.version && (
                                <p className="text-sm text-destructive">
                                    {errors.version}
                                </p>
                            )}
                        </div>
                    </div>
                </StatGroup>

                <ConfirmDialog
                    open={versionToSwitchTo !== null}
                    onOpenChange={(open) => !open && setVersionToSwitchTo(null)}
                    title={
                        isReinstall
                            ? `Reinstall ${versionToSwitchTo?.label ?? ''}?`
                            : `Switch to ${versionToSwitchTo?.label ?? ''}?`
                    }
                    description={switchDescription(versionToSwitchTo)}
                    confirmLabel={isReinstall ? 'Reinstall' : 'Switch version'}
                    loading={isSubmitting}
                    onConfirm={handleSwitchVersion}
                />
            </div>
        </AppLayout>
    );
}
