import { Head, router, useForm, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    CheckCircle2Icon,
    DownloadIcon,
    MoreVerticalIcon,
    PlusIcon,
    RotateCcwIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    getPaginationUrls,
    Pagination,
    type PaginationMeta,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useServerBackupsUpdates } from '@/hooks/use-server-backups-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Backup, type BackupSchedule } from '@/types/backup';
import { type Server, type ServerDatabase } from '@/types/server';
import { type StorageProvider } from '@/types/storage-provider';

interface Props {
    server: { data: Server } | Server;
    serverDatabase: { data: ServerDatabase } | ServerDatabase;
    schedule: { data: BackupSchedule } | null;
    backups: {
        data: Backup[];
        links?: unknown;
        meta?: PaginationMeta;
    };
    storageProviders: {
        data: StorageProvider[];
    };
}

function formatBytes(bytes: number | null): string {
    if (bytes === null) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

export default function ServerDatabaseBackupsIndex({
    server: serverProp,
    serverDatabase: serverDatabaseProp,
    schedule: scheduleProp,
    backups,
    storageProviders,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = 'data' in serverProp ? serverProp.data : serverProp;
    const serverDatabase =
        'data' in serverDatabaseProp
            ? serverDatabaseProp.data
            : serverDatabaseProp;
    const schedule = scheduleProp?.data ?? null;

    useServerBackupsUpdates(server.id);

    const basePath = `/servers/${server.id}/databases/${serverDatabase.id}/backups`;
    const schedulePath = `/servers/${server.id}/databases/${serverDatabase.id}/backup-schedule`;

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [backupToDelete, setBackupToDelete] = useState<Backup | null>(null);
    const [restoreOpen, setRestoreOpen] = useState(false);
    const [backupToRestore, setBackupToRestore] = useState<Backup | null>(null);
    const [restoreConfirmText, setRestoreConfirmText] = useState('');
    const [isBackingUp, setIsBackingUp] = useState(false);

    const scheduleForm = useForm({
        storage_provider_id: schedule?.storage_provider_id ?? '',
        frequency: schedule?.frequency ?? 'daily',
        retention_count: schedule?.retention_count ?? 7,
        enabled: schedule?.enabled ?? false,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        {
            title: 'Databases',
            href: teamPath(`/servers/${server.id}/databases`),
        },
        { title: `${serverDatabase.name} Backups`, href: teamPath(basePath) },
    ];

    const handleSaveSchedule = (e: React.FormEvent) => {
        e.preventDefault();
        scheduleForm.put(teamPath(schedulePath), {
            preserveScroll: true,
        });
    };

    const handleBackupNow = () => {
        setIsBackingUp(true);
        router.post(
            teamPath(basePath),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsBackingUp(false),
            },
        );
    };

    const handleDelete = (backup: Backup) => {
        setBackupToDelete(backup);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!backupToDelete) return;
        router.delete(teamPath(`${basePath}/${backupToDelete.id}`), {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setBackupToDelete(null);
            },
        });
    };

    const handleRestore = (backup: Backup) => {
        setBackupToRestore(backup);
        setRestoreConfirmText('');
        setRestoreOpen(true);
    };

    const confirmRestore = () => {
        if (!backupToRestore) return;
        router.post(
            teamPath(`${basePath}/${backupToRestore.id}/restore`),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setRestoreOpen(false);
                    setBackupToRestore(null);
                    setRestoreConfirmText('');
                },
            },
        );
    };

    const { prevUrl, nextUrl } = getPaginationUrls(backups.links);

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(
                currentTeam?.slug ?? '',
                server.id,
            )}
        >
            <Head title={`Backups - ${serverDatabase.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Backups
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Scheduled and manual backups of{' '}
                            <span className="font-mono">
                                {serverDatabase.name}
                            </span>
                            .
                        </p>
                    </div>
                    <Button
                        onClick={handleBackupNow}
                        disabled={!schedule || isBackingUp}
                    >
                        <PlusIcon className="mr-2 h-4 w-4" />
                        {isBackingUp ? 'Starting…' : 'Backup now'}
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Schedule</CardTitle>
                        <CardDescription>
                            Automatically back up this database on a recurring
                            schedule.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {storageProviders.data.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Connect a storage provider first, then come back
                                to set up a schedule.
                            </p>
                        ) : (
                            <form
                                onSubmit={handleSaveSchedule}
                                className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <div className="space-y-2">
                                    <Label htmlFor="storage_provider_id">
                                        Storage provider
                                    </Label>
                                    <Select
                                        value={String(
                                            scheduleForm.data
                                                .storage_provider_id,
                                        )}
                                        onValueChange={(value) =>
                                            scheduleForm.setData(
                                                'storage_provider_id',
                                                Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger id="storage_provider_id">
                                            <SelectValue placeholder="Select storage" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {storageProviders.data.map(
                                                (provider) => (
                                                    <SelectItem
                                                        key={provider.id}
                                                        value={String(
                                                            provider.id,
                                                        )}
                                                    >
                                                        {provider.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="frequency">Frequency</Label>
                                    <Select
                                        value={scheduleForm.data.frequency}
                                        onValueChange={(value) =>
                                            scheduleForm.setData(
                                                'frequency',
                                                value as
                                                    | 'hourly'
                                                    | 'daily'
                                                    | 'weekly',
                                            )
                                        }
                                    >
                                        <SelectTrigger id="frequency">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="hourly">
                                                Hourly
                                            </SelectItem>
                                            <SelectItem value="daily">
                                                Daily
                                            </SelectItem>
                                            <SelectItem value="weekly">
                                                Weekly
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="retention_count">
                                        Keep last
                                    </Label>
                                    <Input
                                        id="retention_count"
                                        type="number"
                                        min={1}
                                        max={365}
                                        value={
                                            scheduleForm.data.retention_count
                                        }
                                        onChange={(e) =>
                                            scheduleForm.setData(
                                                'retention_count',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </div>

                                <div className="flex items-end justify-between gap-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="enabled">Enabled</Label>
                                        <div>
                                            <Switch
                                                id="enabled"
                                                checked={
                                                    scheduleForm.data.enabled
                                                }
                                                onCheckedChange={(checked) =>
                                                    scheduleForm.setData(
                                                        'enabled',
                                                        checked,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            scheduleForm.processing ||
                                            !scheduleForm.data
                                                .storage_provider_id
                                        }
                                    >
                                        Save
                                    </Button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>

                {backups.data.length === 0 ? (
                    <EmptyState
                        title="No backups yet"
                        description="Backups will appear here once one runs."
                    />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Triggered by</TableHead>
                                    <TableHead>Size</TableHead>
                                    <TableHead>Verified</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead className="w-[70px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {backups.data.map((backup) => (
                                    <TableRow key={backup.id}>
                                        <TableCell>
                                            <AutoStatusBadge
                                                status={backup.status}
                                            />
                                            {backup.error_message && (
                                                <p className="mt-1 max-w-xs truncate text-xs text-destructive">
                                                    {backup.error_message}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {backup.triggered_by === 'manual'
                                                ? (backup.triggered_by_user ??
                                                  'Manual')
                                                : 'Scheduled'}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {formatBytes(backup.size_bytes)}
                                        </TableCell>
                                        <TableCell>
                                            {backup.verified_at ? (
                                                <CheckCircle2Icon className="h-4 w-4 text-green-600" />
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {format(
                                                new Date(backup.created_at),
                                                'MMM d, yyyy HH:mm',
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
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
                                                    {backup.status ===
                                                        'completed' && (
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <a
                                                                href={teamPath(
                                                                    `${basePath}/${backup.id}/download`,
                                                                )}
                                                            >
                                                                <DownloadIcon className="mr-2 h-4 w-4" />
                                                                Download
                                                            </a>
                                                        </DropdownMenuItem>
                                                    )}
                                                    {backup.status ===
                                                        'completed' && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                handleRestore(
                                                                    backup,
                                                                )
                                                            }
                                                        >
                                                            <RotateCcwIcon className="mr-2 h-4 w-4" />
                                                            Restore
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem
                                                        className="text-destructive focus:text-destructive"
                                                        onClick={() =>
                                                            handleDelete(backup)
                                                        }
                                                    >
                                                        <Trash2Icon className="mr-2 h-4 w-4" />
                                                        Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        <Pagination
                            meta={backups.meta}
                            prevUrl={prevUrl}
                            nextUrl={nextUrl}
                            resultsLabel="backups"
                        />
                    </>
                )}
            </div>

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete backup"
                description="This permanently removes the backup file from storage. This cannot be undone."
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDelete}
            />

            <Dialog open={restoreOpen} onOpenChange={setRestoreOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Restore backup</DialogTitle>
                        <DialogDescription>
                            This overwrites the live data in{' '}
                            <span className="font-mono">
                                {serverDatabase.name}
                            </span>{' '}
                            with this backup. Type the database name to confirm.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="restore-confirm">Database name</Label>
                        <Input
                            id="restore-confirm"
                            value={restoreConfirmText}
                            onChange={(e) =>
                                setRestoreConfirmText(e.target.value)
                            }
                            placeholder={serverDatabase.name}
                            className="font-mono"
                            autoComplete="off"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRestoreOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={
                                restoreConfirmText !== serverDatabase.name
                            }
                            onClick={confirmRestore}
                        >
                            Restore
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
