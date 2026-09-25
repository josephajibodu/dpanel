import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    CheckCircle2Icon,
    DownloadIcon,
    MoreVerticalIcon,
    RotateCcwIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
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
import { AutoStatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { type Backup } from '@/types/backup';

interface Props {
    backups: {
        data: Backup[];
        links?: unknown;
        meta?: PaginationMeta;
    };
    /** Team-prefixed URL of the backups collection; row actions append `/{id}/...`. */
    backupsUrl: string;
    description: string;
    /** Omit to hide the Restore action. */
    onRestore?: (backup: Backup) => void;
}

function formatBytes(bytes: number | null): string {
    if (bytes === null) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

export function BackupHistory({
    backups,
    backupsUrl,
    description,
    onRestore,
}: Props) {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [backupToDelete, setBackupToDelete] = useState<Backup | null>(null);

    const handleDelete = (backup: Backup) => {
        setBackupToDelete(backup);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!backupToDelete) return;
        router.delete(`${backupsUrl}/${backupToDelete.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setBackupToDelete(null);
            },
        });
    };

    const { prevUrl, nextUrl } = getPaginationUrls(backups.links);

    return (
        <div className="space-y-4">
            <div>
                <CardTitle>History</CardTitle>
                <CardDescription className="mt-1">
                    {description}
                </CardDescription>
            </div>

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
                                                    <DropdownMenuItem asChild>
                                                        <a
                                                            href={`${backupsUrl}/${backup.id}/download`}
                                                        >
                                                            <DownloadIcon className="mr-2 h-4 w-4" />
                                                            Download
                                                        </a>
                                                    </DropdownMenuItem>
                                                )}
                                                {onRestore &&
                                                    backup.status ===
                                                        'completed' && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                onRestore(
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

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete backup"
                description="This permanently removes the backup file from storage. This cannot be undone."
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDelete}
            />
        </div>
    );
}
