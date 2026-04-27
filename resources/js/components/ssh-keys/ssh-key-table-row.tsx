import { format } from 'date-fns';
import { InfoIcon, MoreVerticalIcon, ServerIcon, Trash2Icon, UploadIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { type SshKey, type SshKeyServerStatus } from '@/types/ssh-key';

interface SshKeyTableRowProps {
    sshKey: SshKey;
    onOpenDetails: () => void;
    onSync: () => void;
    onDelete: () => void;
}

const statusVariants: Record<SshKeyServerStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'secondary',
    syncing: 'default',
    synced: 'default',
    revoking: 'secondary',
    failed: 'destructive',
};

export function SshKeyTableRow({ sshKey, onOpenDetails, onSync, onDelete }: SshKeyTableRowProps) {
    const count = sshKey.servers_count ?? 0;
    const serverLabel = count === 1 ? 'server' : 'servers';

    return (
        <TableRow
            className="cursor-pointer"
            tabIndex={0}
            aria-label={`Open details for ${sshKey.name}`}
            onClick={() => onOpenDetails()}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpenDetails();
                }
            }}
        >
            <TableCell className="font-medium">{sshKey.name}</TableCell>
            <TableCell>
                <div className="flex min-w-0 flex-col gap-1.5">
                    <div className="text-muted-foreground flex items-center gap-1 text-sm">
                        <ServerIcon className="h-4 w-4 shrink-0" />
                        <span>
                            {count} {serverLabel}
                        </span>
                    </div>
                    {sshKey.servers && sshKey.servers.length > 0 && (
                        <div className="flex max-w-sm flex-wrap gap-1">
                            {sshKey.servers.map((server) => (
                                <Badge key={server.id} variant={statusVariants[server.status]} className="text-xs">
                                    {server.name}
                                </Badge>
                            ))}
                        </div>
                    )}
                </div>
            </TableCell>
            <TableCell className="text-muted-foreground whitespace-nowrap text-sm">
                {format(new Date(sshKey.created_at), 'MMM d, yyyy')}
            </TableCell>
            <TableCell
                className="w-[70px] text-right"
                onClick={(e) => e.stopPropagation()}
                onKeyDown={(e) => e.stopPropagation()}
            >
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8">
                            <MoreVerticalIcon className="h-4 w-4" />
                            <span className="sr-only">Actions</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => onOpenDetails()}>
                            <InfoIcon className="mr-2 h-4 w-4" />
                            View details
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onSync}>
                            <UploadIcon className="mr-2 h-4 w-4" />
                            Sync to Servers
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onDelete} className="text-destructive">
                            <Trash2Icon className="mr-2 h-4 w-4" />
                            Delete Key
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}
