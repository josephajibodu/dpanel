import { Head, router, usePage } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { BackupHistory } from '@/components/backups/backup-history';
import { BackupScheduleCard } from '@/components/backups/backup-schedule-card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type PaginationMeta } from '@/components/ui/pagination';
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

    const [restoreOpen, setRestoreOpen] = useState(false);
    const [backupToRestore, setBackupToRestore] = useState<Backup | null>(null);
    const [restoreConfirmText, setRestoreConfirmText] = useState('');
    const [isBackingUp, setIsBackingUp] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        {
            title: 'Databases',
            href: teamPath(`/servers/${server.id}/databases`),
        },
        { title: `${serverDatabase.name} Backups`, href: teamPath(basePath) },
    ];

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

                <BackupScheduleCard
                    schedule={schedule}
                    storageProviders={storageProviders.data}
                    scheduleUrl={teamPath(schedulePath)}
                    description="Automatically back up this database on a recurring schedule."
                />

                <BackupHistory
                    backups={backups}
                    backupsUrl={teamPath(basePath)}
                    description="All backups for this database."
                    onRestore={handleRestore}
                />
            </div>

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
