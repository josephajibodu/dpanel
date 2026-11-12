import { Head, router, usePage } from '@inertiajs/react';
import { LinkIcon, PlusIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { useState } from 'react';

import { BackupHistory } from '@/components/backups/backup-history';
import { BackupScheduleCard } from '@/components/backups/backup-schedule-card';
import { ImportDumpDialog } from '@/components/backups/import-dump-dialog';
import { RestoreBackupDialog } from '@/components/backups/restore-backup-dialog';
import { CopyButton } from '@/components/copy-button';
import { ConnectionInfoDialog } from '@/components/databases/connection-info-dialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { type PaginationMeta } from '@/components/ui/pagination';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { usePlan } from '@/hooks/use-plan';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Backup, type BackupSchedule } from '@/types/backup';
import { type DatabaseConnection } from '@/types/server';
import { type Site } from '@/types/site';
import { type StorageProvider } from '@/types/storage-provider';

interface Props {
    server: { data: { id: number; name: string } };
    site: { data: Site };
    usesSqlite: boolean;
    sqlitePath: string | null;
    databaseConnection: DatabaseConnection | null;
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

const DATABASE_ENGINE_LABELS: Record<string, string> = {
    mysql: 'MySQL',
    postgresql: 'PostgreSQL',
    mariadb: 'MariaDB',
};

function DetailItem({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <p className="text-sm text-muted-foreground">{label}</p>
            <div className="mt-2 text-sm font-medium">{children}</div>
        </div>
    );
}

export default function SiteDatabaseShow({
    server: serverProp,
    site: siteProp,
    usesSqlite,
    sqlitePath,
    databaseConnection,
    schedule: scheduleProp,
    backups,
    storageProviders,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const can = usePermission();
    const plan = usePlan();
    const server = serverProp.data;
    const site = siteProp.data;
    const schedule = scheduleProp?.data ?? null;
    const database = databaseConnection?.database ?? null;

    const sitePath = `/servers/${server.id}/sites/${site.id}`;
    const databasePath = database
        ? `/servers/${database.server_id}/databases/${database.id}`
        : sitePath;
    const backupsPath = `${databasePath}/backups`;
    const schedulePath = `${databasePath}/backup-schedule`;
    const hasDatabase = database !== null || usesSqlite;

    const [isBackingUp, setIsBackingUp] = useState(false);
    const [connectOpen, setConnectOpen] = useState(false);
    const [backupToRestore, setBackupToRestore] = useState<Backup | null>(null);
    const [isImportOpen, setIsImportOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: site.domain, href: teamPath(sitePath) },
        { title: 'Database', href: teamPath(`${sitePath}/database`) },
    ];

    const handleBackupNow = () => {
        setIsBackingUp(true);
        router.post(
            teamPath(backupsPath),
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
            subNavItems={getSiteSubNavItems(
                currentTeam?.slug ?? '',
                server.id,
                site.id,
            )}
        >
            <Head title={`Database — ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Database
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The database this site uses, and its backups.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {database && can('restore_backups') && (
                            <Button
                                variant="outline"
                                onClick={() => setIsImportOpen(true)}
                            >
                                <UploadSimpleIcon className="h-4 w-4" />
                                Import dump
                            </Button>
                        )}
                        {hasDatabase && can('run_backups') && (
                            <Button
                                onClick={handleBackupNow}
                                disabled={
                                    !schedule ||
                                    isBackingUp ||
                                    Boolean(plan?.notIncluded.database_backups)
                                }
                            >
                                <PlusIcon className="h-4 w-4" />
                                {isBackingUp ? 'Starting…' : 'Backup now'}
                            </Button>
                        )}
                    </div>
                </div>

                {databaseConnection && database ? (
                    <div className="rounded-2xl bg-card shadow-card">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                            <div className="flex items-center gap-3">
                                <span className="font-mono text-base font-semibold">
                                    {database.name}
                                </span>
                                <AutoStatusBadge status={database.status} />
                            </div>
                            {database.status === 'ready' && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConnectOpen(true)}
                                >
                                    <LinkIcon className="h-4 w-4" />
                                    Connect
                                </Button>
                            )}
                        </div>
                        <div className="grid gap-5 p-6 sm:grid-cols-3">
                            <DetailItem label="Engine">
                                {DATABASE_ENGINE_LABELS[
                                    databaseConnection.server.database_type ??
                                        ''
                                ] ?? '—'}
                            </DetailItem>
                            <DetailItem label="Server">
                                {databaseConnection.server.name}
                                {databaseConnection.server.ip_address && (
                                    <p className="mt-1 font-mono text-xs font-normal text-muted-foreground">
                                        {databaseConnection.server.ip_address}
                                    </p>
                                )}
                            </DetailItem>
                            <DetailItem label="Users with access">
                                {databaseConnection.databaseUsers.length > 0 ? (
                                    <span className="font-mono">
                                        {databaseConnection.databaseUsers
                                            .map((user) =>
                                                user.permission === 'readonly'
                                                    ? `${user.username} (read-only)`
                                                    : user.username,
                                            )
                                            .join(', ')}
                                    </span>
                                ) : (
                                    <span className="font-normal text-muted-foreground">
                                        None yet
                                    </span>
                                )}
                            </DetailItem>
                        </div>
                    </div>
                ) : usesSqlite ? (
                    <div className="rounded-2xl bg-card shadow-card">
                        <div className="border-b px-6 py-4">
                            <span className="text-base font-semibold">
                                SQLite
                            </span>
                        </div>
                        <div className="space-y-2 p-6">
                            <p className="text-sm text-muted-foreground">
                                This site keeps its data in a SQLite file on{' '}
                                {server.name}. It stays in place across
                                deployments.
                            </p>
                            {sqlitePath && (
                                <div className="flex min-w-0 items-center gap-1 rounded-lg border bg-muted p-2.5">
                                    <code className="min-w-0 flex-1 truncate font-mono text-xs">
                                        {sqlitePath}
                                    </code>
                                    <CopyButton
                                        value={sqlitePath}
                                        className="shrink-0"
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    <EmptyState
                        title="This site doesn't use a database"
                        description="There's no server database or SQLite file linked to this site."
                    />
                )}

                {hasDatabase && (
                    <>
                        <BackupScheduleCard
                            schedule={schedule}
                            storageProviders={storageProviders.data}
                            scheduleUrl={teamPath(schedulePath)}
                            description={
                                database
                                    ? `Automatically back up ${database.name} on a recurring schedule.`
                                    : "Automatically back up this site's SQLite database on a recurring schedule."
                            }
                        />

                        <BackupHistory
                            backups={backups}
                            backupsUrl={teamPath(backupsPath)}
                            description={
                                database
                                    ? `All backups of ${database.name}.`
                                    : "All backups of this site's SQLite database."
                            }
                            onRestore={
                                database && can('restore_backups')
                                    ? setBackupToRestore
                                    : undefined
                            }
                        />
                    </>
                )}
            </div>

            {connectOpen && databaseConnection && database && (
                <ConnectionInfoDialog
                    server={databaseConnection.server}
                    sshUser={databaseConnection.sshUser}
                    hasSshKey={databaseConnection.hasSshKey}
                    databases={[database]}
                    databaseUsers={databaseConnection.databaseUsers}
                    initialDatabase={database.name}
                    teamPath={teamPath}
                    onOpenChange={setConnectOpen}
                />
            )}

            {isImportOpen && database && (
                <ImportDumpDialog
                    databaseName={database.name}
                    importUrl={teamPath(`${databasePath}/imports`)}
                    storageProviders={storageProviders.data}
                    defaultStorageProviderId={
                        schedule?.storage_provider_id ?? null
                    }
                    onClose={() => setIsImportOpen(false)}
                />
            )}

            {backupToRestore && database && (
                <RestoreBackupDialog
                    backup={backupToRestore}
                    databaseName={database.name}
                    backupsUrl={teamPath(backupsPath)}
                    onClose={() => setBackupToRestore(null)}
                />
            )}
        </AppLayout>
    );
}
