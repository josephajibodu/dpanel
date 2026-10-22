import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTeamPath } from '@/hooks/use-team-path';
import { type Server } from '@/types/server';
import { type IsolatedSiteTarget, type SshKey } from '@/types/ssh-key';
import { router } from '@inertiajs/react';
import { GlobeIcon, ServerIcon, UploadIcon } from 'lucide-react';
import { useState } from 'react';

interface SyncServersDialogProps {
    sshKey: SshKey;
    servers: Server[] | { data: Server[] };
    isolatedSites: IsolatedSiteTarget[];
    /** Whether the user may put keys on a server's own user (full root access). */
    canSyncToServerUser: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function SyncServersDialog({ sshKey, servers, isolatedSites, canSyncToServerUser, open, onOpenChange }: SyncServersDialogProps) {
    const teamPath = useTeamPath();
    const [selectedServerIds, setSelectedServerIds] = useState<number[]>([]);
    const [selectedSiteIds, setSelectedSiteIds] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);

    const serversList = Array.isArray(servers) ? servers : (servers?.data ?? []);
    const synced = sshKey.servers?.filter((s) => s.status === 'synced') ?? [];

    // Hide targets this key is already on.
    const syncedServerIds = synced.filter((s) => s.site_id === null).map((s) => s.id);
    const syncedSiteIds = synced.map((s) => s.site_id).filter((id): id is number => id !== null);
    const availableServers = canSyncToServerUser
        ? serversList.filter((s) => !syncedServerIds.includes(s.id) && s.status === 'active')
        : [];
    const availableSites = isolatedSites.filter((s) => !syncedSiteIds.includes(s.id));
    const selectedCount = selectedServerIds.length + selectedSiteIds.length;

    const toggle = (ids: number[], id: number) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]);

    const handleSubmit = () => {
        if (selectedCount === 0) return;

        setProcessing(true);
        router.post(
            teamPath(`/ssh-keys/${sshKey.id}/sync`),
            { server_ids: selectedServerIds, site_ids: selectedSiteIds },
            {
                onFinish: () => {
                    setProcessing(false);
                    onOpenChange(false);
                    setSelectedServerIds([]);
                    setSelectedSiteIds([]);
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
                        Sync SSH Key
                    </DialogTitle>
                    <DialogDescription>
                        Choose where "{sshKey.name}" can log in. A server gives full access to that server; an isolated site gives
                        SSH and SFTP access to that site only.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <p className="text-sm font-medium">Servers (full access)</p>
                        {!canSyncToServerUser ? (
                            <p className="text-muted-foreground text-xs">
                                Only Owners and Admins can add keys with full server access, because that user can act as root.
                            </p>
                        ) : availableServers.length === 0 ? (
                            <p className="text-muted-foreground text-xs">
                                {serversList.length === 0 ? 'No active servers available.' : 'This key is already on every available server.'}
                            </p>
                        ) : (
                            <div className="max-h-[180px] space-y-2 overflow-y-auto rounded-lg border p-3">
                                {availableServers.map((server) => (
                                    <div key={server.id} className="flex items-center gap-3 rounded-md p-2 transition-colors hover:bg-muted">
                                        <Checkbox
                                            id={`server-${server.id}`}
                                            checked={selectedServerIds.includes(server.id)}
                                            onCheckedChange={() => setSelectedServerIds((ids) => toggle(ids, server.id))}
                                        />
                                        <Label htmlFor={`server-${server.id}`} className="flex flex-1 cursor-pointer items-center gap-2">
                                            <ServerIcon className="h-4 w-4" />
                                            <span>{server.name}</span>
                                            <span className="text-muted-foreground text-xs">{server.ip_address}</span>
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="space-y-2">
                        <p className="text-sm font-medium">Isolated sites (that site only)</p>
                        {availableSites.length === 0 ? (
                            <p className="text-muted-foreground text-xs">
                                {isolatedSites.length === 0 ? 'No isolated sites yet.' : 'This key is already on every isolated site.'}
                            </p>
                        ) : (
                            <div className="max-h-[180px] space-y-2 overflow-y-auto rounded-lg border p-3">
                                {availableSites.map((site) => (
                                    <div key={site.id} className="flex items-center gap-3 rounded-md p-2 transition-colors hover:bg-muted">
                                        <Checkbox
                                            id={`site-${site.id}`}
                                            checked={selectedSiteIds.includes(site.id)}
                                            onCheckedChange={() => setSelectedSiteIds((ids) => toggle(ids, site.id))}
                                        />
                                        <Label htmlFor={`site-${site.id}`} className="flex flex-1 cursor-pointer items-center gap-2">
                                            <GlobeIcon className="h-4 w-4" />
                                            <span>{site.domain}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {site.server_name} · {site.system_user}
                                            </span>
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={handleSubmit} disabled={processing || selectedCount === 0}>
                        {processing ? 'Syncing...' : `Sync to ${selectedCount} target${selectedCount !== 1 ? 's' : ''}`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
