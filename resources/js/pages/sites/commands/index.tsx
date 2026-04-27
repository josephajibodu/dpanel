import { Head, router } from '@inertiajs/react';
import {
    FileTextIcon,
    Loader2Icon,
    MoreVerticalIcon,
    PlayIcon,
    TerminalIcon,
} from 'lucide-react';
import { useCallback, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Site } from '@/types/site';

interface CommandRun {
    id: number;
    command: string;
    status: string;
    status_label: string;
    status_color: string;
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
    const server = serverProp?.data ?? serverProp;
    const site = siteProp?.data ?? siteProp;
    const serverId = server?.id ?? site?.server?.id;
    const siteId = site?.id;
    useServerCommandRunUpdates(Number(serverId ?? 0), siteId ? Number(siteId) : undefined);

    const [runSheetOpen, setRunSheetOpen] = useState(false);
    const [commandInput, setCommandInput] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [logsDialogOpen, setLogsDialogOpen] = useState(false);
    const [logsRun, setLogsRun] = useState<CommandRun | null>(null);
    const [logsContent, setLogsContent] = useState<string>('');
    const [logsLoading, setLogsLoading] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        {
            title: server?.name || site?.server?.name || 'Server',
            href: `/servers/${serverId}`,
        },
        {
            title: site?.domain ?? 'Site',
            href: `/servers/${serverId}/sites/${siteId}`,
        },
        {
            title: 'Commands',
            href: `/servers/${serverId}/sites/${siteId}/command-runs`,
        },
    ];

    const handleRunCommand = (e: React.FormEvent) => {
        e.preventDefault();
        if (!serverId || !siteId || !commandInput.trim()) return;
        setIsSubmitting(true);
        router.post(
            `/servers/${serverId}/sites/${siteId}/command-runs`,
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
                `/servers/${serverId}/sites/${siteId}/command-runs/${run.id}`,
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
            `/servers/${serverId}/sites/${siteId}/command-runs`,
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
                        <p className="text-muted-foreground mt-1 text-sm">
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
                            icon={TerminalIcon}
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
                                            <TableHead>Exit</TableHead>
                                            <TableHead>Finished</TableHead>
                                            <TableHead className="w-0" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {runs.map((run) => (
                                            <TableRow key={run.id}>
                                                <TableCell className="font-mono text-sm max-w-[320px] truncate">
                                                    {truncateCommand(run.command)}
                                                </TableCell>
                                                <TableCell>
                                                    <span
                                                        className={
                                                            {
                                                                pending:
                                                                    'inline-flex min-w-[100px] items-center justify-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
                                                                running:
                                                                    'inline-flex min-w-[100px] items-center justify-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200',
                                                                completed:
                                                                    'inline-flex min-w-[100px] items-center justify-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
                                                                failed:
                                                                    'inline-flex min-w-[100px] items-center justify-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
                                                            }[run.status] ??
                                                            'inline-flex min-w-[100px] items-center justify-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200'
                                                        }
                                                    >
                                                        {run.status === 'running' && (
                                                            <Loader2Icon className="h-3 w-3 animate-spin" />
                                                        )}
                                                        {run.status_label}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {run.exit_code ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground whitespace-nowrap text-sm">
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
                                                        <DropdownMenuTrigger asChild>
                                                            <Button
                                                                variant="ghost"
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
                                                                    openLogs(run)
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
                                className="text-muted-foreground mb-2 block text-sm font-medium"
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
                                className="bg-muted/50 border-input focus-visible:ring-ring min-h-[120px] w-full resize-y rounded-md border px-3 py-2 font-mono text-sm focus-visible:outline-none focus-visible:ring-2"
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
                                disabled={
                                    isSubmitting || !commandInput.trim()
                                }
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
                                <span className="text-muted-foreground ml-2 font-mono text-sm font-normal">
                                    {truncateCommand(logsRun.command, 40)}
                                </span>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="px-6 pb-6">
                        <div className="max-h-[60vh] min-h-[200px] overflow-y-auto rounded-md border bg-muted/30 p-3">
                        {logsLoading ? (
                            <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Loader2Icon className="h-4 w-4 animate-spin" />
                                Loading…
                            </p>
                        ) : (
                            <pre className="font-mono text-xs whitespace-pre-wrap break-words">
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
