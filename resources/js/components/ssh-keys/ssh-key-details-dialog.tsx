import { format } from 'date-fns';
import { KeyIcon, ServerIcon } from 'lucide-react';

import { CopyButton } from '@/components/copy-button';
import { StatusBadge, type StatusColor } from '@/components/status-badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type SshKey, type SshKeyServerStatus } from '@/types/ssh-key';

interface SshKeyDetailsDialogProps {
    sshKey: SshKey;
    onOpenChange: (open: boolean) => void;
}

const statusInfo: Record<
    SshKeyServerStatus,
    { label: string; color: StatusColor; pulse?: boolean }
> = {
    pending: { label: 'Pending', color: 'gray' },
    syncing: { label: 'Syncing', color: 'blue', pulse: true },
    synced: { label: 'Synced', color: 'green' },
    revoking: { label: 'Revoking', color: 'orange', pulse: true },
    failed: { label: 'Failed', color: 'red' },
};

function DetailField({
    label,
    value,
    copyValue,
}: {
    label: string;
    value: string;
    copyValue: string;
}) {
    return (
        <div className="space-y-1.5">
            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="flex min-w-0 items-start gap-1 rounded-md border bg-muted/80 p-2.5">
                <code className="min-w-0 flex-1 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap text-foreground">
                    {value}
                </code>
                <CopyButton value={copyValue} className="shrink-0" />
            </div>
        </div>
    );
}

export function SshKeyDetailsDialog({
    sshKey,
    onOpenChange,
}: SshKeyDetailsDialogProps) {
    const count = sshKey.servers_count ?? 0;
    const serverLabel = count === 1 ? 'server' : 'servers';

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 pr-6">
                        <KeyIcon className="h-5 w-5 shrink-0 text-primary" />
                        {sshKey.name}
                    </DialogTitle>
                    <DialogDescription>
                        Fingerprint and public key for this SSH key.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <DetailField
                        label="Fingerprint"
                        value={sshKey.fingerprint}
                        copyValue={sshKey.fingerprint}
                    />
                    <DetailField
                        label="Public key"
                        value={sshKey.public_key_preview}
                        copyValue={sshKey.public_key_preview}
                    />

                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <ServerIcon className="h-4 w-4" />
                            {count} {serverLabel}
                        </span>
                        <span>·</span>
                        <span>
                            Added{' '}
                            {format(new Date(sshKey.created_at), 'MMM d, yyyy')}
                        </span>
                    </div>

                    {sshKey.servers && sshKey.servers.length > 0 && (
                        <div className="space-y-1.5">
                            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Sync status
                            </div>
                            <div className="max-h-48 space-y-1.5 overflow-y-auto">
                                {sshKey.servers.map((server) => {
                                    const info = statusInfo[server.status];
                                    return (
                                        <div
                                            key={server.id}
                                            className="flex items-center justify-between gap-2 rounded-md border p-2.5"
                                        >
                                            <span className="truncate text-sm font-medium">
                                                {server.name}
                                            </span>
                                            <StatusBadge
                                                status={info.label}
                                                color={info.color}
                                                pulse={info.pulse}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
