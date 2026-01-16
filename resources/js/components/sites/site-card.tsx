import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Site } from '@/types/site';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import { ExternalLinkIcon, GitBranchIcon, GlobeIcon, MoreVerticalIcon, Trash2Icon } from 'lucide-react';

interface SiteCardProps {
    site: Site;
    onDelete?: () => void;
}

export function SiteCard({ site, onDelete }: SiteCardProps) {
    return (
        <Card className="relative">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-3">
                        <div className="bg-muted flex h-10 w-10 items-center justify-center rounded-lg">
                            <GlobeIcon className="h-5 w-5" />
                        </div>
                        <div>
                            <CardTitle className="text-base">{site.domain}</CardTitle>
                            <CardDescription>{site.project_type_label}</CardDescription>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <SiteStatusBadge status={site.status} statusLabel={site.status_label} statusColor={site.status_color} />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="h-8 w-8">
                                    <MoreVerticalIcon className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <Link href={`/sites/${site.id}`}>View Details</Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={`https://${site.domain}`} target="_blank" rel="noopener noreferrer">
                                        <ExternalLinkIcon className="mr-2 h-4 w-4" />
                                        Visit Site
                                    </a>
                                </DropdownMenuItem>
                                {onDelete && site.status !== 'installing' && (
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
                {site.repository && (
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground text-sm">Repository</span>
                        <div className="flex items-center gap-1.5 text-sm">
                            <GitBranchIcon className="h-4 w-4" />
                            <span className="max-w-[150px] truncate">{site.short_repository}</span>
                        </div>
                    </div>
                )}

                <div className="flex items-center justify-between">
                    <span className="text-muted-foreground text-sm">Branch</span>
                    <span className="text-sm font-mono">{site.branch}</span>
                </div>

                <div className="flex items-center justify-between">
                    <span className="text-muted-foreground text-sm">PHP</span>
                    <span className="text-sm">PHP {site.php_version}</span>
                </div>

                {site.latest_deployment && (
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground text-sm">Last Deploy</span>
                        <span className="text-sm">
                            {site.latest_deployment.finished_at
                                ? format(new Date(site.latest_deployment.finished_at), 'MMM d, HH:mm')
                                : 'In progress...'}
                        </span>
                    </div>
                )}

                <div className="pt-2">
                    <Button variant="outline" size="sm" className="w-full" asChild>
                        <Link href={`/sites/${site.id}`}>View Details</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
