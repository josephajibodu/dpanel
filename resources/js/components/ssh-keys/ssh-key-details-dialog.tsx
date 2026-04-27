import { format } from 'date-fns';
import { KeyIcon, ServerIcon } from 'lucide-react';

import { CopyButton } from '@/components/copy-button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type SshKey, type SshKeyServerStatus } from '@/types/ssh-key';

interface SshKeyDetailsDialogProps {
    sshKey: SshKey;
    onOpenChange: (open: boolean) => void;
}

const statusVariants: Record<SshKeyServerStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'secondary',
    syncing: 'default',
    synced: 'default',
    revoking: 'secondary',
    failed: 'destructive',
};

function DetailField({ label, value, copyValue }: { label: string; value: string; copyValue: string }) {
    return (
        <div className="space-y-1.5">
            <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
            <div className="bg-muted/80 flex min-w-0 items-start gap-1 rounded-md border p-2.5">
                <code className="text-foreground min-w-0 flex-1 break-words font-mono text-xs leading-relaxed whitespace-pre-wrap">
                    {value}
                </code>
                <CopyButton value={copyValue} className="shrink-0" />
            </div>
        </div>
    );
}

export function SshKeyDetailsDialog({ sshKey, onOpenChange }: SshKeyDetailsDialogProps) {
    const count = sshKey.servers_count ?? 0;
    const serverLabel = count === 1 ? 'server' : 'servers';

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 pr-6">
                        <KeyIcon className="text-primary h-5 w-5 shrink-0" />
                        {sshKey.name}
                    </DialogTitle>
                    <DialogDescription>Fingerprint and public key for this SSH key.</DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <DetailField label="Fingerprint" value={sshKey.fingerprint} copyValue={sshKey.fingerprint} />
                    <DetailField label="Public key" value={sshKey.public_key_preview} copyValue={sshKey.public_key_preview} />

                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span className="inline-flex items-center gap-1">
                            <ServerIcon className="h-4 w-4" />
                            {count} {serverLabel}
                        </span>
                        <span>·</span>
                        <span>Added {format(new Date(sshKey.created_at), 'MMM d, yyyy')}</span>
                    </div>

                    {sshKey.servers && sshKey.servers.length > 0 && (
                        <div className="space-y-1.5">
                            <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Sync status</div>
                            <div className="flex flex-wrap gap-1.5">
                                {sshKey.servers.map((server) => (
                                    <Badge key={server.id} variant={statusVariants[server.status]}>
                                        {server.name}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
