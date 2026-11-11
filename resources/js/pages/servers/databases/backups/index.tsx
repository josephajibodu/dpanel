import { Head, router, usePage } from '@inertiajs/react';
import { PlusIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { useState } from 'react';

import { BackupHistory } from '@/components/backups/backup-history';
import { BackupScheduleCard } from '@/components/backups/backup-schedule-card';
import { ImportDumpDialog } from '@/components/backups/import-dump-dialog';
import { RestoreBackupDialog } from '@/components/backups/restore-backup-dialog';
import { Button } from '@/components/ui/button';
import { type PaginationMeta } from '@/components/ui/pagination';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
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
    const can = usePermission();
    const server = 'data' in serverProp ? serverProp.data : serverProp;
    const serverDatabase =
        'data' in serverDatabaseProp
            ? serverDatabaseProp.data
            : serverDatabaseProp;
    const schedule = scheduleProp?.data ?? null;

    const basePath = `/servers/${server.id}/databases/${serverDatabase.id}/backups`;
    const schedulePath = `/servers/${server.id}/databases/${serverDatabase.id}/backup-schedule`;

    const [backupToRestore, setBackupToRestore] = useState<Backup | null>(null);
    const [isImportOpen, setIsImportOpen] = useState(false);
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

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(currentTeam?.slug ?? '', server)}
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
                    <div className="flex gap-2">
                        {can('restore_backups') && (
                            <Button
                                variant="outline"
                                onClick={() => setIsImportOpen(true)}
                            >
                                <UploadSimpleIcon className="h-4 w-4" />
                                Import dump
                            </Button>
                        )}
                        {can('run_backups') && (
                            <Button
                                onClick={handleBackupNow}
                                disabled={!schedule || isBackingUp}
                            >
                                <PlusIcon className="h-4 w-4" />
                                {isBackingUp ? 'Starting…' : 'Backup now'}
                            </Button>
                        )}
                    </div>
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
                    onRestore={
                        can('restore_backups') ? setBackupToRestore : undefined
                    }
                />
            </div>

            {isImportOpen && (
                <ImportDumpDialog
                    databaseName={serverDatabase.name}
                    importUrl={teamPath(
                        `/servers/${server.id}/databases/${serverDatabase.id}/imports`,
                    )}
                    storageProviders={storageProviders.data}
                    defaultStorageProviderId={
                        schedule?.storage_provider_id ?? null
                    }
                    onClose={() => setIsImportOpen(false)}
                />
            )}

            {backupToRestore && (
                <RestoreBackupDialog
                    backup={backupToRestore}
                    databaseName={serverDatabase.name}
                    backupsUrl={teamPath(basePath)}
                    onClose={() => setBackupToRestore(null)}
                />
            )}
        </AppLayout>
    );
}
