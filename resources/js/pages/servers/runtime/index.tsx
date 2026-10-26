import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatGroup } from '@/components/ui/stat-group';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useServerRuntimeUpdates } from '@/hooks/use-server-runtime-updates';
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

    useServerRuntimeUpdates(server.id);

    const [selectedVersion, setSelectedVersion] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const isChanging =
        node.status === 'pending' || node.status === 'installing';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Runtime', href: teamPath(`/servers/${server.id}/runtime`) },
    ];

    const handleSwitchVersion = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedVersion) return;
        setIsSubmitting(true);
        router.put(
            teamPath(`/servers/${server.id}/runtime/node`),
            { version: selectedVersion },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedVersion(''),
                onFinish: () => setIsSubmitting(false),
            },
        );
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
                    <div className="rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20">
                        <div className="border-b px-6 py-4">
                            <div className="flex items-center justify-between">
                                <CardTitle>Node.js</CardTitle>
                                <AutoStatusBadge status={node.status} />
                            </div>
                            <CardDescription className="mt-1">
                                Used by every site on this server when a deploy
                                runs <span className="font-mono">npm</span>{' '}
                                commands, such as building front-end assets.
                            </CardDescription>
                        </div>
                        <div className="flex flex-col gap-4 p-6">
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Installed version
                                </p>
                                <p className="font-mono text-lg font-medium">
                                    {node.label}
                                </p>
                                {node.is_end_of_life && (
                                    <p className="text-sm text-destructive">
                                        This version no longer gets security
                                        updates. Switch to a newer version.
                                    </p>
                                )}
                                {node.status === 'failed' && (
                                    <p className="text-sm text-destructive">
                                        The last version change failed. The
                                        server may still be on the version
                                        above; try switching again.
                                    </p>
                                )}
                            </div>

                            {canManageRuntime && (
                                <form
                                    onSubmit={handleSwitchVersion}
                                    className="flex flex-col gap-2"
                                >
                                    <Label htmlFor="node-version">
                                        Switch version
                                    </Label>
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <Select
                                            value={selectedVersion}
                                            onValueChange={setSelectedVersion}
                                            disabled={
                                                !serverIsReady || isChanging
                                            }
                                        >
                                            <SelectTrigger
                                                id="node-version"
                                                className="sm:w-64"
                                            >
                                                <SelectValue placeholder="Select a version" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availableNodeVersions
                                                    .filter(
                                                        (v) =>
                                                            v.value !==
                                                            node.version,
                                                    )
                                                    .map((v) => (
                                                        <SelectItem
                                                            key={v.value}
                                                            value={v.value}
                                                        >
                                                            {v.label}
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                        <Button
                                            type="submit"
                                            disabled={
                                                !serverIsReady ||
                                                isChanging ||
                                                isSubmitting ||
                                                !selectedVersion
                                            }
                                        >
                                            {isChanging
                                                ? 'Switching…'
                                                : 'Switch version'}
                                        </Button>
                                    </div>
                                    {errors?.version && (
                                        <p className="text-sm text-destructive">
                                            {errors.version}
                                        </p>
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        Takes effect from the next deploy.
                                        Processes already running keep the old
                                        version until they restart.
                                    </p>
                                </form>
                            )}
                        </div>
                    </div>
                </StatGroup>
            </div>
        </AppLayout>
    );
}
