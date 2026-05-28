import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { Server } from '@/types/server';
import { Head, router, usePage } from '@inertiajs/react';
import { CodeIcon, DownloadIcon, SettingsIcon } from 'lucide-react';
import { useState } from 'react';

export interface PhpSettings {
    upload_max_filesize?: string;
    post_max_size?: string;
    max_execution_time?: string;
    memory_limit?: string;
}

export interface PhpServiceRow {
    id: number;
    version: string | null;
    installed_version: string | null;
    is_default: boolean;
    status: string;
}

interface Props {
    server: { data: Server } | Server;
    serverIsReady: boolean;
    phpServices: PhpServiceRow[];
    installedVersions: string[];
    defaultVersion: string;
    settings: PhpSettings;
    availableVersions: string[];
}

export default function ServerPhpIndex({
    server: serverProp,
    serverIsReady,
    phpServices,
    installedVersions,
    defaultVersion,
    settings,
    availableVersions,
}: Props) {
    const server =
        serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const phpPageProps = usePage<SharedData>().props;
    const { errors } = phpPageProps as { errors?: Record<string, string> };
    const teamPath = useTeamPath();

    const [isSubmittingSettings, setIsSubmittingSettings] = useState(false);
    const [isSubmittingInstall, setIsSubmittingInstall] = useState(false);
    const [isSubmittingDefault, setIsSubmittingDefault] = useState<string | null>(null);

    const [settingsForm, setSettingsForm] = useState({
        upload_max_filesize: settings?.upload_max_filesize ?? '',
        post_max_size: settings?.post_max_size ?? '',
        max_execution_time: settings?.max_execution_time ?? '',
        memory_limit: settings?.memory_limit ?? '',
    });

    const [installVersion, setInstallVersion] = useState<string>('');

    const versionsToInstall = availableVersions.filter(
        (v) => !installedVersions.includes(v),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'PHP', href: teamPath(`/servers/${server.id}/php`) },
    ];

    const handleUpdateSettings = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id) return;
        setIsSubmittingSettings(true);
        router.put(teamPath(`/servers/${server.id}/php/settings`), {
            upload_max_filesize: settingsForm.upload_max_filesize || undefined,
            post_max_size: settingsForm.post_max_size || undefined,
            max_execution_time: settingsForm.max_execution_time
                ? Number(settingsForm.max_execution_time)
                : undefined,
            memory_limit: settingsForm.memory_limit || undefined,
        }, {
            preserveScroll: true,
            onFinish: () => setIsSubmittingSettings(false),
        });
    };

    const handleInstallVersion = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !installVersion) return;
        setIsSubmittingInstall(true);
        router.post(teamPath(`/servers/${server.id}/php/versions`), { version: installVersion }, {
            preserveScroll: true,
            onSuccess: () => setInstallVersion(''),
            onFinish: () => setIsSubmittingInstall(false),
        });
    };

    const handleSetDefault = (version: string) => {
        if (!server?.id) return;
        setIsSubmittingDefault(version);
        router.patch(teamPath(`/servers/${server.id}/php/default-version`), { version }, {
            preserveScroll: true,
            onFinish: () => setIsSubmittingDefault(null),
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(phpPageProps.currentTeam?.slug ?? '', server.id)}
        >
            <Head title={`PHP - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            PHP
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            PHP versions and settings on {server.name}.
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-1">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <SettingsIcon className="h-5 w-5" />
                                PHP settings
                            </CardTitle>
                            <CardDescription>
                                Configure php.ini for the default PHP version
                                ({defaultVersion || '—'}).
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handleUpdateSettings}
                                className="flex flex-col gap-4"
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="upload_max_filesize">
                                            Upload max filesize
                                        </Label>
                                        <Input
                                            id="upload_max_filesize"
                                            value={settingsForm.upload_max_filesize}
                                            onChange={(e) =>
                                                setSettingsForm((p) => ({
                                                    ...p,
                                                    upload_max_filesize: e.target.value,
                                                }))
                                            }
                                            placeholder="e.g. 64M"
                                            className="font-mono"
                                            disabled={!serverIsReady}
                                        />
                                        {errors?.upload_max_filesize && (
                                            <p className="text-destructive text-sm">
                                                {errors.upload_max_filesize}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="post_max_size">
                                            Post max size
                                        </Label>
                                        <Input
                                            id="post_max_size"
                                            value={settingsForm.post_max_size}
                                            onChange={(e) =>
                                                setSettingsForm((p) => ({
                                                    ...p,
                                                    post_max_size: e.target.value,
                                                }))
                                            }
                                            placeholder="e.g. 64M"
                                            className="font-mono"
                                            disabled={!serverIsReady}
                                        />
                                        {errors?.post_max_size && (
                                            <p className="text-destructive text-sm">
                                                {errors.post_max_size}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="max_execution_time">
                                            Max execution time (seconds)
                                        </Label>
                                        <Input
                                            id="max_execution_time"
                                            type="number"
                                            min={1}
                                            max={86400}
                                            value={settingsForm.max_execution_time}
                                            onChange={(e) =>
                                                setSettingsForm((p) => ({
                                                    ...p,
                                                    max_execution_time: e.target.value,
                                                }))
                                            }
                                            placeholder="e.g. 30"
                                            className="font-mono"
                                            disabled={!serverIsReady}
                                        />
                                        {errors?.max_execution_time && (
                                            <p className="text-destructive text-sm">
                                                {errors.max_execution_time}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="memory_limit">
                                            Memory limit
                                        </Label>
                                        <Input
                                            id="memory_limit"
                                            value={settingsForm.memory_limit}
                                            onChange={(e) =>
                                                setSettingsForm((p) => ({
                                                    ...p,
                                                    memory_limit: e.target.value,
                                                }))
                                            }
                                            placeholder="e.g. 256M"
                                            className="font-mono"
                                            disabled={!serverIsReady}
                                        />
                                        {errors?.memory_limit && (
                                            <p className="text-destructive text-sm">
                                                {errors.memory_limit}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <Button
                                    type="submit"
                                    disabled={!serverIsReady || isSubmittingSettings}
                                >
                                    {isSubmittingSettings
                                        ? 'Saving…'
                                        : 'Save settings'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CodeIcon className="h-5 w-5" />
                                Installed versions
                            </CardTitle>
                            <CardDescription>
                                PHP versions installed on this server. Set which
                                one is used by default.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Version</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Default</TableHead>
                                        <TableHead className="w-[140px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(phpServices.length > 0 ? phpServices : []).length === 0 &&
                                    installedVersions.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="h-24 text-center text-muted-foreground text-sm"
                                            >
                                                No PHP versions detected. Install
                                                one below or ensure the server
                                                is connected.
                                            </TableCell>
                                        </TableRow>
                                    ) : phpServices.length > 0 ? (
                                        phpServices.map((s) => {
                                            const v =
                                                s.installed_version ??
                                                s.version ??
                                                '';
                                            return (
                                                <TableRow key={s.id}>
                                                    <TableCell className="font-mono font-medium">
                                                        {v || '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <span
                                                            className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ${
                                                                s.status === 'active'
                                                                    ? 'bg-green-500/15 text-green-700 dark:text-green-400'
                                                                    : s.status === 'installing'
                                                                      ? 'bg-blue-500/15 text-blue-700 dark:text-blue-400'
                                                                      : s.status === 'failed'
                                                                        ? 'bg-destructive/15 text-destructive'
                                                                        : 'bg-muted text-muted-foreground'
                                                            }`}
                                                        >
                                                            {s.status}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        {s.is_default ? (
                                                            <span className="inline-flex rounded-md bg-green-500/15 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">
                                                                Default
                                                            </span>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {!s.is_default &&
                                                            v && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={
                                                                        !serverIsReady ||
                                                                        isSubmittingDefault ===
                                                                            v ||
                                                                        s.status === 'installing'
                                                                    }
                                                                    onClick={() =>
                                                                        handleSetDefault(
                                                                            v,
                                                                        )
                                                                    }
                                                                >
                                                                    {isSubmittingDefault ===
                                                                    v
                                                                        ? 'Setting…'
                                                                        : 'Set as default'}
                                                                </Button>
                                                            )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    ) : (
                                        installedVersions.map((v) => (
                                            <TableRow key={v}>
                                                <TableCell className="font-mono font-medium">
                                                    {v}
                                                </TableCell>
                                                <TableCell>—</TableCell>
                                                <TableCell>
                                                    {v === defaultVersion ? (
                                                        <span className="inline-flex rounded-md bg-green-500/15 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">
                                                            Default
                                                        </span>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {v !== defaultVersion && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                !serverIsReady ||
                                                                isSubmittingDefault === v
                                                            }
                                                            onClick={() =>
                                                                handleSetDefault(v)
                                                            }
                                                        >
                                                            {isSubmittingDefault === v
                                                                ? 'Setting…'
                                                                : 'Set as default'}
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <DownloadIcon className="h-5 w-5" />
                                Install PHP version
                            </CardTitle>
                            <CardDescription>
                                Install an additional PHP version from the
                                ondrej/php PPA.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handleInstallVersion}
                                className="flex flex-col gap-4 sm:flex-row sm:items-end"
                            >
                                <div className="space-y-2 sm:min-w-[120px]">
                                    <Label htmlFor="install-version">
                                        Version
                                    </Label>
                                    <Select
                                        value={installVersion}
                                        onValueChange={setInstallVersion}
                                        disabled={!serverIsReady}
                                    >
                                        <SelectTrigger id="install-version">
                                            <SelectValue placeholder="Select version" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {versionsToInstall.map((v) => (
                                                <SelectItem
                                                    key={v}
                                                    value={v}
                                                >
                                                    PHP {v}
                                                </SelectItem>
                                            ))}
                                            {versionsToInstall.length === 0 && (
                                                <SelectItem
                                                    value=""
                                                    disabled
                                                >
                                                    All versions installed
                                                </SelectItem>
                                            )}
                                        </SelectContent>
                                    </Select>
                                    {errors?.version && (
                                        <p className="text-destructive text-sm">
                                            {errors.version}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        !serverIsReady ||
                                        !installVersion ||
                                        versionsToInstall.length === 0 ||
                                        isSubmittingInstall
                                    }
                                >
                                    {isSubmittingInstall
                                        ? 'Installing…'
                                        : 'Install'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
