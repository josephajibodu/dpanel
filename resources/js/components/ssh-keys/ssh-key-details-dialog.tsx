import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { KeyIcon, ServerIcon } from 'lucide-react';
import { useState } from 'react';

import { CopyButton } from '@/components/copy-button';
import { StatusBadge, type StatusColor } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTeamPath } from '@/hooks/use-team-path';
import {
    type SshKey,
    type SshKeyServer,
    type SshKeyServerStatus,
} from '@/types/ssh-key';

interface SshKeyDetailsDialogProps {
    sshKey: SshKey;
    /** This team's servers; the key can only be removed from these here. */
    teamServerIds: number[];
    canRemove: boolean;
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
    teamServerIds,
    canRemove,
    onOpenChange,
}: SshKeyDetailsDialogProps) {
    const teamPath = useTeamPath();
    const [removing, setRemoving] = useState<string | null>(null);
    const count = sshKey.servers_count ?? 0;

    const placementKey = (placement: SshKeyServer) =>
        `${placement.id}-${placement.system_user}`;

    const removeFrom = (placement: SshKeyServer) => {
        setRemoving(placementKey(placement));
        router.post(
            teamPath(`/ssh-keys/${sshKey.id}/revoke`),
            placement.site_id
                ? { site_ids: [placement.site_id] }
                : { server_ids: [placement.id] },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setRemoving(null),
            },
        );
    };
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
                                            key={placementKey(server)}
                                            className="flex items-center justify-between gap-2 rounded-md border p-2.5"
                                        >
                                            <span className="truncate text-sm font-medium">
                                                {server.name}
                                                <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                    {server.system_user}
                                                </span>
                                            </span>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <StatusBadge
                                                    status={info.label}
                                                    color={info.color}
                                                    pulse={info.pulse}
                                                />
                                                {canRemove &&
                                                    teamServerIds.includes(
                                                        server.id,
                                                    ) &&
                                                    server.status !==
                                                        'revoking' && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            disabled={
                                                                removing !==
                                                                null
                                                            }
                                                            onClick={() =>
                                                                removeFrom(
                                                                    server,
                                                                )
                                                            }
                                                        >
                                                            Remove
                                                        </Button>
                                                    )}
                                            </div>
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
