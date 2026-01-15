import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { sync } from '@/actions/App/Http/Controllers/SshKeyController';
import { type Server } from '@/types/server';
import { type SshKey } from '@/types/ssh-key';
import { router } from '@inertiajs/react';
import { ServerIcon, UploadIcon } from 'lucide-react';
import { useState } from 'react';

interface SyncServersDialogProps {
    sshKey: SshKey;
    servers: Server[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function SyncServersDialog({ sshKey, servers, open, onOpenChange }: SyncServersDialogProps) {
    const [selectedServerIds, setSelectedServerIds] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);

    // Filter out servers that already have this key synced
    const syncedServerIds = sshKey.servers?.filter((s) => s.status === 'synced').map((s) => s.id) ?? [];
    const availableServers = servers.filter((s) => !syncedServerIds.includes(s.id) && s.status === 'active');

    const handleToggleServer = (serverId: number) => {
        setSelectedServerIds((prev) => (prev.includes(serverId) ? prev.filter((id) => id !== serverId) : [...prev, serverId]));
    };

    const handleSelectAll = () => {
        if (selectedServerIds.length === availableServers.length) {
            setSelectedServerIds([]);
        } else {
            setSelectedServerIds(availableServers.map((s) => s.id));
        }
    };

    const handleSubmit = () => {
        if (selectedServerIds.length === 0) return;

        setProcessing(true);
        router.post(
            sync.url(sshKey.id),
            { server_ids: selectedServerIds },
            {
                onFinish: () => {
                    setProcessing(false);
                    onOpenChange(false);
                    setSelectedServerIds([]);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <UploadIcon className="h-5 w-5" />
                        Sync SSH Key to Servers
                    </DialogTitle>
                    <DialogDescription>
                        Select the servers you want to sync "{sshKey.name}" to. The key will be added to the authorized_keys file.
                    </DialogDescription>
                </DialogHeader>

                {availableServers.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <ServerIcon className="text-muted-foreground mb-2 h-8 w-8" />
                        <p className="text-muted-foreground text-sm">
                            {servers.length === 0 ? 'No active servers available.' : 'This key is already synced to all available servers.'}
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Button type="button" variant="outline" size="sm" onClick={handleSelectAll}>
                                {selectedServerIds.length === availableServers.length ? 'Deselect All' : 'Select All'}
                            </Button>
                            <span className="text-muted-foreground text-sm">{selectedServerIds.length} selected</span>
                        </div>

                        <div className="max-h-[300px] space-y-2 overflow-y-auto rounded-lg border p-3">
                            {availableServers.map((server) => (
                                <div
                                    key={server.id}
                                    className="flex items-center gap-3 rounded-md p-2 transition-colors hover:bg-muted"
                                >
                                    <Checkbox
                                        id={`server-${server.id}`}
                                        checked={selectedServerIds.includes(server.id)}
                                        onCheckedChange={() => handleToggleServer(server.id)}
                                    />
                                    <Label
                                        htmlFor={`server-${server.id}`}
                                        className="flex flex-1 cursor-pointer items-center gap-2"
                                    >
                                        <ServerIcon className="h-4 w-4" />
                                        <span>{server.name}</span>
                                        <span className="text-muted-foreground text-xs">{server.ip_address}</span>
                                    </Label>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSubmit}
                        disabled={processing || selectedServerIds.length === 0}
                    >
                        {processing ? 'Syncing...' : `Sync to ${selectedServerIds.length} Server${selectedServerIds.length !== 1 ? 's' : ''}`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
