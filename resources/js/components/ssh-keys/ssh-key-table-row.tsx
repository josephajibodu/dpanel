import {
    DotsThreeVerticalIcon,
    HardDrivesIcon,
    InfoIcon,
    TrashIcon,
    UploadSimpleIcon,
} from '@phosphor-icons/react';
import { format } from 'date-fns';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { type SshKey } from '@/types/ssh-key';

interface SshKeyTableRowProps {
    sshKey: SshKey;
    onOpenDetails: () => void;
    /** Omit to hide "Sync to Servers" (e.g. when the user has no server access). */
    onSync?: () => void;
    onDelete: () => void;
}

export function SshKeyTableRow({
    sshKey,
    onOpenDetails,
    onSync,
    onDelete,
}: SshKeyTableRowProps) {
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
                <div className="flex items-center gap-1 text-sm text-muted-foreground">
                    <HardDrivesIcon className="h-4 w-4 shrink-0" />
                    <span>
                        {count} {serverLabel}
                    </span>
                </div>
            </TableCell>
            <TableCell className="whitespace-nowrap text-muted-foreground">
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
                            <DotsThreeVerticalIcon className="h-4 w-4" />
                            <span className="sr-only">Actions</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => onOpenDetails()}>
                            <InfoIcon className="mr-2 h-4 w-4" />
                            View details
                        </DropdownMenuItem>
                        {onSync && (
                            <DropdownMenuItem onClick={onSync}>
                                <UploadSimpleIcon className="mr-2 h-4 w-4" />
                                Sync to Servers
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onClick={onDelete}
                            className="text-destructive"
                        >
                            <TrashIcon className="mr-2 h-4 w-4" />
                            Delete Key
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}
