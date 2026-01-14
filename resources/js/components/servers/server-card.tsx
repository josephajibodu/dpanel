import { ServerStatusBadge } from '@/components/servers/server-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Server } from '@/types/server';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { GlobeIcon, MoreVerticalIcon, ServerIcon, Trash2Icon } from 'lucide-react';

interface ServerCardProps {
    server: Server;
    onDelete?: () => void;
}

export function ServerCard({ server, onDelete }: ServerCardProps) {
    return (
        <Card className="relative">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-3">
                        <div className="bg-muted flex h-10 w-10 items-center justify-center rounded-lg">
                            <ServerIcon className="h-5 w-5" />
                        </div>
                        <div>
                            <CardTitle className="text-base">{server.name}</CardTitle>
                            <CardDescription>{server.provider_label}</CardDescription>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <ServerStatusBadge status={server.status} statusLabel={server.status_label} statusColor={server.status_color} />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="h-8 w-8">
                                    <MoreVerticalIcon className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <Link href={`/servers/${server.id}`}>View Details</Link>
                                </DropdownMenuItem>
                                {onDelete && server.status !== 'deleting' && (
                                    <DropdownMenuItem onClick={onDelete} className="text-destructive focus:text-destructive">
                                        <Trash2Icon className="mr-2 h-4 w-4" />
                                        Delete
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="flex items-center justify-between">
                    <span className="text-muted-foreground text-sm">IP Address</span>
                    <div className="flex items-center gap-1.5 font-mono text-sm">
                        <GlobeIcon className="h-4 w-4" />
                        {server.ip_address || 'Pending...'}
                    </div>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-muted-foreground text-sm">Region</span>
                    <span className="text-sm">{server.region}</span>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-muted-foreground text-sm">Sites</span>
                    <span className="text-sm font-medium">{server.sites_count ?? 0}</span>
                </div>

                {server.provisioned_at && (
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground text-sm">Provisioned</span>
                        <span className="text-sm">{format(new Date(server.provisioned_at), 'MMM d, yyyy')}</span>
                    </div>
                )}

                <div className="pt-2">
                    <Button variant="outline" size="sm" className="w-full" asChild>
                        <Link href={`/servers/${server.id}`}>View Details</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
