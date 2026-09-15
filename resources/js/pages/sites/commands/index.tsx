import { Head, router, usePage } from '@inertiajs/react';
import {
    FileTextIcon,
    Loader2Icon,
    MoreVerticalIcon,
    PlayIcon,
    TerminalIcon,
} from 'lucide-react';
import { useCallback, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { StatusBadge, type StatusColor } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    getPaginationUrls,
    Pagination,
    type PaginationMeta,
} from '@/components/ui/pagination';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useServerCommandRunUpdates } from '@/hooks/use-server-command-run-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Site } from '@/types/site';

interface CommandRun {
    id: number;
    command: string;
    status: string;
    status_label: string;
    status_color: StatusColor;
    exit_code: number | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
    updated_at: string;
    user?: { id: number; name: string };
}

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
        };
    };
    commandRuns: {
        data: CommandRun[];
        links?: unknown;
        meta?: PaginationMeta;
    };
}

function truncateCommand(cmd: string, maxLen: number = 60): string {
    if (cmd.length <= maxLen) return cmd;
    return cmd.slice(0, maxLen) + '…';
}

export default function SiteCommandsIndex({
    server: serverProp,
    site: siteProp,
    commandRuns,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp?.data ?? siteProp;
    const serverId = server?.id ?? site?.server?.id;
    const siteId = site?.id;
    useServerCommandRunUpdates(
        Number(serverId ?? 0),
        siteId ? Number(siteId) : undefined,
    );

    const [runSheetOpen, setRunSheetOpen] = useState(false);
    const [commandInput, setCommandInput] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [logsDialogOpen, setLogsDialogOpen] = useState(false);
    const [logsRun, setLogsRun] = useState<CommandRun | null>(null);
    const [logsContent, setLogsContent] = useState<string>('');
    const [logsLoading, setLogsLoading] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        {
            title: server?.name || site?.server?.name || 'Server',
            href: teamPath(`/servers/${serverId}`),
        },
        {
            title: site?.domain ?? 'Site',
            href: teamPath(`/servers/${serverId}/sites/${siteId}`),
        },
        {
            title: 'Commands',
            href: teamPath(`/servers/${serverId}/sites/${siteId}/command-runs`),
        },
    ];

    const handleRunCommand = (e: React.FormEvent) => {
        e.preventDefault();
        if (!serverId || !siteId || !commandInput.trim()) return;
        setIsSubmitting(true);
        router.post(
            teamPath(`/servers/${serverId}/sites/${siteId}/command-runs`),
            { command: commandInput.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRunSheetOpen(false);
                    setCommandInput('');
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const openLogs = useCallback(
        (run: CommandRun) => {
            setLogsRun(run);
            setLogsDialogOpen(true);
            setLogsContent('');
            setLogsLoading(true);
            fetch(
                teamPath(
                    `/servers/${serverId}/sites/${siteId}/command-runs/${run.id}`,
                ),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            )
                .then((r) => r.json())
                .then((data) => setLogsContent(data.output ?? ''))
                .catch(() => setLogsContent('Failed to load output.'))
                .finally(() => setLogsLoading(false));
        },
        [serverId, siteId],
    );

    const handleRunAgain = (run: CommandRun) => {
        if (!serverId || !siteId) return;
        router.post(
            teamPath(`/servers/${serverId}/sites/${siteId}/command-runs`),
            { command: run.command },
            { preserveScroll: true },
        );
    };

    const runs = commandRuns?.data ?? [];
    const { prevUrl, nextUrl } = getPaginationUrls(commandRuns?.links);

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(
                currentTeam?.slug ?? '',
                String(serverId ?? ''),
                String(siteId ?? ''),
            )}
        >
            <Head title={`Commands - ${site?.domain ?? 'Site'}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Commands
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Run commands on the server in this site’s directory.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setRunSheetOpen(true)}
                    >
                        <TerminalIcon className="mr-2 h-4 w-4" />
                        Run command
                    </Button>
                </div>

                <div className="space-y-4">
                    {runs.length === 0 ? (
                        <EmptyState
                            title="No command runs yet"
                            description="Run a command to see output and history here."
                        />
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Command</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">
                                                Exit
                                            </TableHead>
                                            <TableHead>Finished</TableHead>
                                            <TableHead className="w-0" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {runs.map((run) => (
                                            <TableRow key={run.id}>
                                                <TableCell className="max-w-[320px] truncate font-mono text-sm">
                                                    {truncateCommand(
                                                        run.command,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={
                                                            run.status_label
                                                        }
                                                        color={run.status_color}
                                                        pulse={
                                                            run.status ===
                                                            'running'
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right font-mono text-sm text-muted-foreground">
                                                    {run.exit_code ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                    {run.finished_at
                                                        ? new Date(
                                                              run.finished_at,
                                                          ).toLocaleString()
                                                        : run.started_at
                                                          ? 'Running…'
                                                          : '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            asChild
                                                        >
                                                            <Button
                                                                variant="outline"
                                                                size="icon"
                                                                className="h-8 w-8"
                                                                aria-label="Actions"
                                                            >
                                                                <MoreVerticalIcon className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    openLogs(
                                                                        run,
                                                                    )
                                                                }
                                                            >
                                                                <FileTextIcon className="mr-2 h-4 w-4" />
                                                                View logs
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    handleRunAgain(
                                                                        run,
                                                                    )
                                                                }
                                                            >
                                                                <PlayIcon className="mr-2 h-4 w-4" />
                                                                Run again
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            {(prevUrl || nextUrl) && (
                                <Pagination
                                    prevUrl={prevUrl}
                                    nextUrl={nextUrl}
                                    meta={commandRuns.meta}
                                />
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Run command sheet */}
            <Sheet open={runSheetOpen} onOpenChange={setRunSheetOpen}>
                <SheetContent>
                    <form onSubmit={handleRunCommand}>
                        <SheetHeader>
                            <SheetTitle>Run command</SheetTitle>
                        </SheetHeader>
                        <div className="py-6">
                            <label
                                htmlFor="command-input"
                                className="mb-2 block text-sm font-medium text-muted-foreground"
                            >
                                Command (runs in site root on the server)
                            </label>
                            <textarea
                                id="command-input"
                                value={commandInput}
                                onChange={(e) =>
                                    setCommandInput(e.target.value)
                                }
                                placeholder="e.g. php artisan --version"
                                className="min-h-[120px] w-full resize-y rounded-md border border-input bg-muted/50 px-3 py-2 font-mono text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={4}
                            />
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRunSheetOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={isSubmitting || !commandInput.trim()}
                            >
                                {isSubmitting ? (
                                    <>
                                        <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                        Running…
                                    </>
                                ) : (
                                    <>
                                        <PlayIcon className="mr-2 h-4 w-4" />
                                        Run
                                    </>
                                )}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* View logs dialog */}
            <Dialog open={logsDialogOpen} onOpenChange={setLogsDialogOpen}>
                <DialogContent className="max-h-[80vh] max-w-2xl overflow-hidden p-0">
                    <DialogHeader className="px-6 pt-6">
                        <DialogTitle>
                            Command output
                            {logsRun && (
                                <span className="ml-2 font-mono text-sm font-normal text-muted-foreground">
                                    {truncateCommand(logsRun.command, 40)}
                                </span>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="px-6 pb-6">
                        <div className="max-h-[60vh] min-h-[200px] overflow-y-auto rounded-md border bg-muted/30 p-3">
                            {logsLoading ? (
                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Loader2Icon className="h-4 w-4 animate-spin" />
                                    Loading…
                                </p>
                            ) : (
                                <pre className="font-mono text-xs break-words whitespace-pre-wrap">
                                    {logsContent || 'No output.'}
                                </pre>
                            )}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
