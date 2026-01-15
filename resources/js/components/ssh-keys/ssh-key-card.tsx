import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type SshKey, type SshKeyServerStatus } from '@/types/ssh-key';
import { format } from 'date-fns';
import { KeyIcon, MoreVerticalIcon, ServerIcon, Trash2Icon, UploadIcon } from 'lucide-react';

interface SshKeyCardProps {
    sshKey: SshKey;
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

export function SshKeyCard({ sshKey, onSync, onDelete }: SshKeyCardProps) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between space-y-0">
                <div className="flex items-start gap-3">
                    <div className="bg-primary/10 text-primary rounded-lg p-2">
                        <KeyIcon className="h-5 w-5" />
                    </div>
                    <div>
                        <CardTitle className="text-lg">{sshKey.name}</CardTitle>
                        <CardDescription className="font-mono text-xs">{sshKey.fingerprint}</CardDescription>
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8">
                            <MoreVerticalIcon className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
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
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="bg-muted rounded-lg p-3">
                    <code className="text-muted-foreground break-all text-xs">{sshKey.public_key_preview}</code>
                </div>

                <div className="flex items-center justify-between text-sm">
                    <div className="text-muted-foreground flex items-center gap-1">
                        <ServerIcon className="h-4 w-4" />
                        <span>
                            {sshKey.servers_count ?? 0} {(sshKey.servers_count ?? 0) === 1 ? 'server' : 'servers'}
                        </span>
                    </div>
                    <span className="text-muted-foreground text-xs">Added {format(new Date(sshKey.created_at), 'MMM d, yyyy')}</span>
                </div>

                {sshKey.servers && sshKey.servers.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {sshKey.servers.map((server) => (
                            <Badge key={server.id} variant={statusVariants[server.status]}>
                                {server.name}
                            </Badge>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
