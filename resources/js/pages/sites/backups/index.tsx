import { Head, Link, router, usePage } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { BackupHistory } from '@/components/backups/backup-history';
import { BackupScheduleCard } from '@/components/backups/backup-schedule-card';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { type PaginationMeta } from '@/components/ui/pagination';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useServerBackupsUpdates } from '@/hooks/use-server-backups-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Backup, type BackupSchedule } from '@/types/backup';
import { type Site } from '@/types/site';
import { type StorageProvider } from '@/types/storage-provider';

interface Props {
    server: { data: { id: number; name: string } };
    site: { data: Site };
    usesSqlite: boolean;
    serverDatabase: { id: number; name: string } | null;
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

export default function SiteBackupsIndex({
    server: serverProp,
    site: siteProp,
    usesSqlite,
    serverDatabase,
    schedule: scheduleProp,
    backups,
    storageProviders,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp.data;
    const site = siteProp.data;
    const schedule = scheduleProp?.data ?? null;

    useServerBackupsUpdates(server.id);

    const sitePath = `/servers/${server.id}/sites/${site.id}`;
    const basePath = `${sitePath}/backups`;

    const [isBackingUp, setIsBackingUp] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: site.domain, href: teamPath(sitePath) },
        { title: 'Backups', href: teamPath(basePath) },
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
            subNavItems={getSiteSubNavItems(
                currentTeam?.slug ?? '',
                server.id,
                site.id,
            )}
        >
            <Head title={`Backups — ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Backups
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Scheduled and manual backups of this site&apos;s
                            SQLite database.
                        </p>
                    </div>
                    {usesSqlite && (
                        <Button
                            onClick={handleBackupNow}
                            disabled={!schedule || isBackingUp}
                        >
                            <PlusIcon className="mr-2 h-4 w-4" />
                            {isBackingUp ? 'Starting…' : 'Backup now'}
                        </Button>
                    )}
                </div>

                {usesSqlite ? (
                    <>
                        <BackupScheduleCard
                            schedule={schedule}
                            storageProviders={storageProviders.data}
                            scheduleUrl={teamPath(
                                `${sitePath}/backup-schedule`,
                            )}
                            description="Automatically back up this site's SQLite database on a recurring schedule."
                        />

                        <BackupHistory
                            backups={backups}
                            backupsUrl={teamPath(basePath)}
                            description="All backups of this site's SQLite database."
                        />
                    </>
                ) : serverDatabase ? (
                    <EmptyState
                        title="This site uses a server database"
                        description={`Backups for ${serverDatabase.name} are managed from the server's Databases page.`}
                        action={
                            <Button asChild variant="outline">
                                <Link
                                    href={teamPath(
                                        `/servers/${server.id}/databases/${serverDatabase.id}/backups`,
                                    )}
                                >
                                    View database backups
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <EmptyState
                        title="No SQLite database to back up"
                        description="Site backups currently cover Laravel sites that use SQLite."
                    />
                )}
            </div>
        </AppLayout>
    );
}
