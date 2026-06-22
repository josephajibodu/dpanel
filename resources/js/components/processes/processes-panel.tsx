import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { useTeamPath } from '@/hooks/use-team-path';
import type { SharedData } from '@/types';
import type {
    CronJob,
    ProcessSite,
    Server,
    Worker,
} from '@/types/server';
import { router, usePage } from '@inertiajs/react';
import {
    ClockIcon,
    FileTextIcon,
    Loader2Icon,
    PencilIcon,
    PlayIcon,
    PlusIcon,
    PowerIcon,
    PowerOffIcon,
    RefreshCwIcon,
    Trash2Icon,
} from 'lucide-react';

import { useCallback, useState } from 'react';

const CRON_FREQUENCIES: { value: string; label: string }[] = [
    { value: '* * * * *', label: 'Every minute' },
    { value: '*/5 * * * *', label: 'Every 5 minutes' },
    { value: '*/15 * * * *', label: 'Every 15 minutes' },
    { value: '*/30 * * * *', label: 'Every 30 minutes' },
    { value: '0 * * * *', label: 'Every hour' },
    { value: '0 0 * * *', label: 'Daily' },
    { value: '0 0 * * 0', label: 'Weekly' },
];

function truncate(str: string, max: number) {
    if (str.length <= max) return str;
    return str.slice(0, max) + '…';
}

export interface ProcessesPanelProps {
    server: Server;
    /**
     * When provided, the panel is scoped to this site: workers/cron jobs all
     * belong to it, the site picker is hidden in the create/edit dialogs (the
     * site_id is pre-set), and the "Site" column is hidden in the tables.
     */
    site?: { id: number; domain: string } | null;
    serverIsReady: boolean;
    workers: Worker[];
    cronJobs: CronJob[];
    /** Used only when `site` is not provided (for the picker on the server-scoped page). */
    sites?: ProcessSite[];
    /** The default system user for new workers and cron jobs (from server config). */
    defaultUser: string;
    /** Defaults to "Workers and cron jobs on {server name}". */
    headingDescription?: string;
}

export function ProcessesPanel({
    server,
    site = null,
    serverIsReady,
    workers,
    cronJobs,
    sites = [],
    defaultUser,
    headingDescription,
}: ProcessesPanelProps) {
    const scope: 'server' | 'site' = site ? 'site' : 'server';
    const teamPath = useTeamPath();
    const pageProps = usePage<SharedData>().props;
    const { errors } = pageProps as { errors?: Record<string, string> };

    const [createWorkerOpen, setCreateWorkerOpen] = useState(false);
    const [editWorkerOpen, setEditWorkerOpen] = useState(false);
    const [workerToEdit, setWorkerToEdit] = useState<Worker | null>(null);
    const [deleteWorkerOpen, setDeleteWorkerOpen] = useState(false);
    const [workerToDelete, setWorkerToDelete] = useState<Worker | null>(null);
    const [createCronOpen, setCreateCronOpen] = useState(false);
    const [editCronOpen, setEditCronOpen] = useState(false);
    const [cronToEdit, setCronToEdit] = useState<CronJob | null>(null);
    const [deleteCronOpen, setDeleteCronOpen] = useState(false);
    const [cronToDelete, setCronToDelete] = useState<CronJob | null>(null);
    const [logsOpen, setLogsOpen] = useState(false);
    const [logsWorker, setLogsWorker] = useState<Worker | null>(null);
    const [logsContent, setLogsContent] = useState('');
    const [logsLoading, setLogsLoading] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [deletingWorkerIds, setDeletingWorkerIds] = useState<number[]>([]);
    const [deletingCronIds, setDeletingCronIds] = useState<number[]>([]);

    const defaultSiteId: number | '' = site ? site.id : '';

    const [workerForm, setWorkerForm] = useState({
        name: '',
        command: '',
        site_id: defaultSiteId as number | '',
        user: defaultUser,
        numprocs: 1,
        auto_start: true,
        auto_restart: true,
        stdout_logfile: '',
    });
    const [cronForm, setCronForm] = useState({
        command: '',
        site_id: defaultSiteId as number | '',
        user: defaultUser,
        frequency: '* * * * *',
    });
    const [customCronFreq, setCustomCronFreq] = useState(false);

    const resolveSiteId = (value: number | ''): number | null => {
        if (scope === 'site' && site) return site.id;
        if (value === '' || (value as unknown) === 'server') return null;
        return Number(value);
    };

    const fetchLogs = useCallback(
        (w: Worker) => {
            if (!server?.id) return;
            setLogsWorker(w);
            setLogsOpen(true);
            setLogsContent('');
            setLogsLoading(true);
            fetch(teamPath(`/servers/${server.id}/workers/${w.id}/logs`), {
                headers: { Accept: 'text/plain', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((r) => r.text())
                .then((text) => setLogsContent(text))
                .catch(() => setLogsContent('Failed to load logs.'))
                .finally(() => setLogsLoading(false));
        },
        [server?.id, teamPath],
    );

    const handleCreateWorker = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id) return;
        setIsSubmitting(true);
        router.post(
            teamPath(`/servers/${server.id}/workers`),
            {
                name: workerForm.name,
                command: workerForm.command,
                site_id: resolveSiteId(workerForm.site_id),
                user: workerForm.user,
                numprocs: workerForm.numprocs,
                auto_start: workerForm.auto_start,
                auto_restart: workerForm.auto_restart,
                stdout_logfile: workerForm.stdout_logfile || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCreateWorkerOpen(false);
                    setWorkerForm({
                        name: '',
                        command: '',
                        site_id: defaultSiteId,
                        user: defaultUser,
                        numprocs: 1,
                        auto_start: true,
                        auto_restart: true,
                        stdout_logfile: '',
                    });
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleUpdateWorker = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !workerToEdit) return;
        setIsSubmitting(true);
        router.put(
            teamPath(`/servers/${server.id}/workers/${workerToEdit.id}`),
            {
                name: workerForm.name,
                command: workerForm.command,
                site_id: resolveSiteId(workerForm.site_id),
                user: workerForm.user,
                numprocs: workerForm.numprocs,
                auto_start: workerForm.auto_start,
                auto_restart: workerForm.auto_restart,
                stdout_logfile: workerForm.stdout_logfile || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditWorkerOpen(false);
                    setWorkerToEdit(null);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const openEditWorker = (w: Worker) => {
        setWorkerToEdit(w);
        setWorkerForm({
            name: w.name,
            command: w.command,
            site_id: w.site_id ?? defaultSiteId,
            user: w.user,
            numprocs: w.numprocs,
            auto_start: w.auto_start,
            auto_restart: w.auto_restart,
            stdout_logfile: w.stdout_logfile ?? '',
        });
        setEditWorkerOpen(true);
    };

    const confirmDeleteWorker = () => {
        if (!server?.id || !workerToDelete) return;
        const id = workerToDelete.id;
        setDeleteWorkerOpen(false);
        setWorkerToDelete(null);
        setDeletingWorkerIds((prev) => [...prev, id]);
        router.delete(teamPath(`/servers/${server.id}/workers/${id}`), {
            preserveScroll: true,
            onSuccess: () => setDeletingWorkerIds((prev) => prev.filter((x) => x !== id)),
            onError: () => setDeletingWorkerIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    const handleCreateCron = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id) return;
        setIsSubmitting(true);
        router.post(
            teamPath(`/servers/${server.id}/cron-jobs`),
            {
                command: cronForm.command,
                site_id: resolveSiteId(cronForm.site_id),
                user: cronForm.user,
                frequency: cronForm.frequency,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCreateCronOpen(false);
                    setCronForm({
                        command: '',
                        site_id: defaultSiteId,
                        user: defaultUser,
                        frequency: '* * * * *',
                    });
                    setCustomCronFreq(false);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleUpdateCron = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !cronToEdit) return;
        setIsSubmitting(true);
        router.put(
            teamPath(`/servers/${server.id}/cron-jobs/${cronToEdit.id}`),
            {
                command: cronForm.command,
                site_id: resolveSiteId(cronForm.site_id),
                user: cronForm.user,
                frequency: cronForm.frequency,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditCronOpen(false);
                    setCronToEdit(null);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const openEditCron = (c: CronJob) => {
        setCronToEdit(c);
        setCronForm({
            command: c.command,
            site_id: c.site_id ?? defaultSiteId,
            user: c.user,
            frequency: c.frequency,
        });
        setCustomCronFreq(!CRON_FREQUENCIES.some((f) => f.value === c.frequency));
        setEditCronOpen(true);
    };

    const confirmDeleteCron = () => {
        if (!server?.id || !cronToDelete) return;
        const id = cronToDelete.id;
        setDeleteCronOpen(false);
        setCronToDelete(null);
        setDeletingCronIds((prev) => [...prev, id]);
        router.delete(teamPath(`/servers/${server.id}/cron-jobs/${id}`), {
            preserveScroll: true,
            onSuccess: () => setDeletingCronIds((prev) => prev.filter((x) => x !== id)),
            onError: () => setDeletingCronIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    const frequencyLabel = (value: string) =>
        CRON_FREQUENCIES.find((f) => f.value === value)?.label ?? value;

    const showSiteColumn = scope === 'server';
    const showSitePicker = scope === 'server';

    const workersDescription =
        scope === 'site'
            ? `Supervisor-style workers for ${site!.domain}.`
            : 'Supervisor-style workers on this server.';
    const cronDescription =
        scope === 'site'
            ? `Scheduled cron jobs for ${site!.domain}.`
            : 'Scheduled cron jobs on this server.';

    const pageHeadingDescription =
        headingDescription ??
        (scope === 'site'
            ? `Workers and cron jobs for ${site!.domain}.`
            : `Workers and cron jobs on ${server.name}.`);

    return (
        <>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Processes</h1>
                    <p className="text-muted-foreground text-sm">{pageHeadingDescription}</p>
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-1">
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <PowerIcon className="h-5 w-5" />
                                    Workers
                                </CardTitle>
                                <CardDescription>{workersDescription}</CardDescription>
                            </div>
                            {serverIsReady && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCreateWorkerOpen(true)}
                                >
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    New worker
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Command</TableHead>
                                    <TableHead>User</TableHead>
                                    {showSiteColumn && <TableHead>Site</TableHead>}
                                    <TableHead>Status</TableHead>
                                    <TableHead>Numprocs</TableHead>
                                    <TableHead className="w-[220px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {workers.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={showSiteColumn ? 7 : 6}
                                            className="h-24 text-center text-muted-foreground text-sm"
                                        >
                                            No workers yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    workers.map((w) => {
                                        const isWorkerDeleting =
                                            deletingWorkerIds.includes(w.id) ||
                                            w.status === 'deleting';
                                        return (
                                        <TableRow key={w.id}>
                                            <TableCell className="font-medium">{w.name}</TableCell>
                                            <TableCell className="max-w-[200px] truncate font-mono text-sm">
                                                {truncate(w.command, 40)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm font-mono">
                                                {w.user}
                                            </TableCell>
                                            {showSiteColumn && (
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {w.site?.domain ?? 'Server'}
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <StatusBadge
                                                    status={isWorkerDeleting ? 'deleting' : w.status}
                                                    label={isWorkerDeleting ? 'Deleting...' : undefined}
                                                />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {w.numprocs}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isWorkerDeleting}
                                                        onClick={() => openEditWorker(w)}
                                                        aria-label="Edit worker"
                                                    >
                                                        <PencilIcon className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isWorkerDeleting}
                                                        onClick={() => fetchLogs(w)}
                                                        aria-label="View logs"
                                                    >
                                                        <FileTextIcon className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isWorkerDeleting}
                                                        onClick={() =>
                                                            router.post(
                                                                teamPath(`/servers/${server.id}/workers/${w.id}/start`),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        aria-label="Start"
                                                    >
                                                        <PlayIcon className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isWorkerDeleting}
                                                        onClick={() =>
                                                            router.post(
                                                                teamPath(`/servers/${server.id}/workers/${w.id}/stop`),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        aria-label="Stop"
                                                    >
                                                        <PowerOffIcon className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isWorkerDeleting}
                                                        onClick={() =>
                                                            router.post(
                                                                teamPath(`/servers/${server.id}/workers/${w.id}/restart`),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        aria-label="Restart"
                                                    >
                                                        <RefreshCwIcon className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isWorkerDeleting}
                                                        onClick={() => {
                                                            setWorkerToDelete(w);
                                                            setDeleteWorkerOpen(true);
                                                        }}
                                                        aria-label="Delete worker"
                                                    >
                                                        <Trash2Icon className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <ClockIcon className="h-5 w-5" />
                                    Cron jobs
                                </CardTitle>
                                <CardDescription>{cronDescription}</CardDescription>
                            </div>
                            {serverIsReady && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCreateCronOpen(true)}
                                >
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    New cron job
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Command</TableHead>
                                    <TableHead>User</TableHead>
                                    <TableHead>Frequency</TableHead>
                                    {showSiteColumn && <TableHead>Site</TableHead>}
                                    <TableHead>Enabled</TableHead>
                                    <TableHead className="w-[120px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {cronJobs.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={showSiteColumn ? 6 : 5}
                                            className="h-24 text-center text-muted-foreground text-sm"
                                        >
                                            No cron jobs yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    cronJobs.map((c) => {
                                        const isCronDeleting =
                                            deletingCronIds.includes(c.id) ||
                                            c.status === 'deleting';
                                        return (
                                        <TableRow key={c.id}>
                                            <TableCell className="max-w-[200px] truncate font-mono text-sm">
                                                {truncate(c.command, 40)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm font-mono">
                                                {c.user}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {frequencyLabel(c.frequency)}
                                            </TableCell>
                                            {showSiteColumn && (
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {c.site?.domain ?? 'Server'}
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                {isCronDeleting ? (
                                                    <StatusBadge status="deleting" label="Deleting..." />
                                                ) : (
                                                    <StatusBadge
                                                        status={c.hidden ? 'disabled' : 'enabled'}
                                                        label={c.hidden ? 'Disabled' : 'Enabled'}
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isCronDeleting}
                                                        onClick={() => openEditCron(c)}
                                                        aria-label="Edit cron job"
                                                    >
                                                        <PencilIcon className="h-4 w-4" />
                                                    </Button>
                                                    {!isCronDeleting && (c.hidden ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="h-8"
                                                            onClick={() =>
                                                                router.visit(
                                                                    teamPath(`/servers/${server.id}/cron-jobs/${c.id}/enable`),
                                                                    { method: 'patch', preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Enable
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="h-8"
                                                            onClick={() =>
                                                                router.visit(
                                                                    teamPath(`/servers/${server.id}/cron-jobs/${c.id}/disable`),
                                                                    { method: 'patch', preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Disable
                                                        </Button>
                                                    ))}
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isCronDeleting}
                                                        onClick={() => {
                                                            setCronToDelete(c);
                                                            setDeleteCronOpen(true);
                                                        }}
                                                        aria-label="Delete cron job"
                                                    >
                                                        <Trash2Icon className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Create worker drawer */}
            <Sheet open={createWorkerOpen} onOpenChange={setCreateWorkerOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Create worker</SheetTitle>
                    </SheetHeader>
                    <form onSubmit={handleCreateWorker} className="flex flex-1 flex-col gap-4 p-4 pt-4">
                        <div className="space-y-2">
                            <Label htmlFor="worker-name">Name</Label>
                            <Input
                                id="worker-name"
                                value={workerForm.name}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, name: e.target.value }))
                                }
                                placeholder="worker-1"
                                autoComplete="off"
                            />
                            {errors?.name && <p className="text-destructive text-sm">{errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="worker-command">Command</Label>
                            <Input
                                id="worker-command"
                                value={workerForm.command}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, command: e.target.value }))
                                }
                                placeholder="php artisan queue:work"
                                className="font-mono"
                            />
                            <p className="text-muted-foreground text-xs">
                                <code>php</code> will use default PHP CLI. For a specific version use a path like{' '}
                                <code className="font-mono">/usr/bin/php8.4</code>.
                            </p>
                            {errors?.command && <p className="text-destructive text-sm">{errors.command}</p>}
                        </div>
                        {showSitePicker ? (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Select
                                    value={
                                        workerForm.site_id === ''
                                            ? 'server'
                                            : String(workerForm.site_id)
                                    }
                                    onValueChange={(v) =>
                                        setWorkerForm((p) => ({
                                            ...p,
                                            site_id: v === 'server' ? '' : Number(v),
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Server (no site)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="server">Server (no site)</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.domain}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Input value={site!.domain} disabled readOnly />
                            </div>
                        )}
                        <div className="space-y-2">
                            <Label htmlFor="worker-user">User</Label>
                            <Input
                                id="worker-user"
                                value={workerForm.user}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, user: e.target.value }))
                                }
                                placeholder="deploy"
                                className="font-mono"
                            />
                            {errors?.user && <p className="text-destructive text-sm">{errors.user}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="worker-numprocs">Numprocs</Label>
                            <Input
                                id="worker-numprocs"
                                type="number"
                                min={1}
                                max={64}
                                value={workerForm.numprocs}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        numprocs: Math.max(1, parseInt(e.target.value, 10) || 1),
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="worker-stdout-logfile">Log file path</Label>
                            <Input
                                id="worker-stdout-logfile"
                                value={workerForm.stdout_logfile}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, stdout_logfile: e.target.value }))
                                }
                                placeholder="/home/artisan/worker.log"
                                className="font-mono"
                            />
                            <p className="text-muted-foreground text-xs">
                                Absolute path where stdout is written. Required to use the Logs button.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="worker-auto-start"
                                checked={workerForm.auto_start}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({ ...p, auto_start: checked === true }))
                                }
                            />
                            <Label htmlFor="worker-auto-start" className="font-normal">
                                Auto start
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="worker-auto-restart"
                                checked={workerForm.auto_restart}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({ ...p, auto_restart: checked === true }))
                                }
                            />
                            <Label htmlFor="worker-auto-restart" className="font-normal">
                                Auto restart
                            </Label>
                        </div>
                        <SheetFooter>
                            <Button type="button" variant="outline" onClick={() => setCreateWorkerOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Creating…' : 'Create worker'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* Edit worker drawer */}
            <Sheet open={editWorkerOpen} onOpenChange={setEditWorkerOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Edit worker {workerToEdit?.name}</SheetTitle>
                    </SheetHeader>
                    <form onSubmit={handleUpdateWorker} className="flex flex-1 flex-col gap-4 p-4 pt-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-name">Name</Label>
                            <Input
                                id="edit-worker-name"
                                value={workerForm.name}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, name: e.target.value }))
                                }
                                autoComplete="off"
                            />
                            {errors?.name && <p className="text-destructive text-sm">{errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-command">Command</Label>
                            <Input
                                id="edit-worker-command"
                                value={workerForm.command}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, command: e.target.value }))
                                }
                                className="font-mono"
                            />
                            {errors?.command && <p className="text-destructive text-sm">{errors.command}</p>}
                        </div>
                        {showSitePicker ? (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Select
                                    value={
                                        workerForm.site_id === ''
                                            ? 'server'
                                            : String(workerForm.site_id)
                                    }
                                    onValueChange={(v) =>
                                        setWorkerForm((p) => ({
                                            ...p,
                                            site_id: v === 'server' ? '' : Number(v),
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Server (no site)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="server">Server (no site)</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.domain}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Input value={site!.domain} disabled readOnly />
                            </div>
                        )}
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-user">User</Label>
                            <Input
                                id="edit-worker-user"
                                value={workerForm.user}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, user: e.target.value }))
                                }
                                className="font-mono"
                            />
                            {errors?.user && <p className="text-destructive text-sm">{errors.user}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-numprocs">Numprocs</Label>
                            <Input
                                id="edit-worker-numprocs"
                                type="number"
                                min={1}
                                max={64}
                                value={workerForm.numprocs}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        numprocs: Math.max(1, parseInt(e.target.value, 10) || 1),
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-stdout-logfile">Log file path</Label>
                            <Input
                                id="edit-worker-stdout-logfile"
                                value={workerForm.stdout_logfile}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({ ...p, stdout_logfile: e.target.value }))
                                }
                                placeholder="/home/artisan/worker.log"
                                className="font-mono"
                            />
                            <p className="text-muted-foreground text-xs">
                                Absolute path where stdout is written. Required to use the Logs button.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="edit-worker-auto-start"
                                checked={workerForm.auto_start}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({ ...p, auto_start: checked === true }))
                                }
                            />
                            <Label htmlFor="edit-worker-auto-start" className="font-normal">
                                Auto start
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="edit-worker-auto-restart"
                                checked={workerForm.auto_restart}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({ ...p, auto_restart: checked === true }))
                                }
                            />
                            <Label htmlFor="edit-worker-auto-restart" className="font-normal">
                                Auto restart
                            </Label>
                        </div>
                        <SheetFooter>
                            <Button type="button" variant="outline" onClick={() => setEditWorkerOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Saving…' : 'Save'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* Create cron drawer */}
            <Sheet open={createCronOpen} onOpenChange={setCreateCronOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Create cron job</SheetTitle>
                    </SheetHeader>
                    <form onSubmit={handleCreateCron} className="flex flex-1 flex-col gap-4 p-4 pt-4">
                        <div className="space-y-2">
                            <Label htmlFor="cron-command">Command</Label>
                            <Input
                                id="cron-command"
                                value={cronForm.command}
                                onChange={(e) =>
                                    setCronForm((p) => ({ ...p, command: e.target.value }))
                                }
                                placeholder="php artisan schedule:run"
                                className="font-mono"
                            />
                            {errors?.command && <p className="text-destructive text-sm">{errors.command}</p>}
                        </div>
                        {showSitePicker ? (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Select
                                    value={
                                        cronForm.site_id === ''
                                            ? 'server'
                                            : String(cronForm.site_id)
                                    }
                                    onValueChange={(v) =>
                                        setCronForm((p) => ({
                                            ...p,
                                            site_id: v === 'server' ? '' : Number(v),
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Server (no site)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="server">Server (no site)</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.domain}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Input value={site!.domain} disabled readOnly />
                            </div>
                        )}
                        <div className="space-y-2">
                            <Label>Frequency</Label>
                            <Select
                                value={customCronFreq ? 'custom' : cronForm.frequency}
                                onValueChange={(v) => {
                                    if (v === 'custom') {
                                        setCustomCronFreq(true);
                                        setCronForm((p) => ({ ...p, frequency: '' }));
                                    } else {
                                        setCustomCronFreq(false);
                                        setCronForm((p) => ({ ...p, frequency: v }));
                                    }
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CRON_FREQUENCIES.map((f) => (
                                        <SelectItem key={f.value} value={f.value}>
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value="custom">Custom…</SelectItem>
                                </SelectContent>
                            </Select>
                            {customCronFreq && (
                                <Input
                                    value={cronForm.frequency}
                                    onChange={(e) =>
                                        setCronForm((p) => ({ ...p, frequency: e.target.value }))
                                    }
                                    placeholder="0 2 * * 1-5"
                                    className="font-mono"
                                    autoFocus
                                />
                            )}
                            {errors?.frequency && <p className="text-destructive text-sm">{errors.frequency}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="cron-user">User</Label>
                            <Input
                                id="cron-user"
                                value={cronForm.user}
                                onChange={(e) =>
                                    setCronForm((p) => ({ ...p, user: e.target.value }))
                                }
                                placeholder="deploy"
                                className="font-mono"
                            />
                            {errors?.user && <p className="text-destructive text-sm">{errors.user}</p>}
                        </div>
                        <SheetFooter>
                            <Button type="button" variant="outline" onClick={() => setCreateCronOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Creating…' : 'Create cron job'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* Edit cron drawer */}
            <Sheet open={editCronOpen} onOpenChange={(open) => { setEditCronOpen(open); if (!open) setCustomCronFreq(false); }}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Edit cron job</SheetTitle>
                    </SheetHeader>
                    <form onSubmit={handleUpdateCron} className="flex flex-1 flex-col gap-4 p-4 pt-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-cron-command">Command</Label>
                            <Input
                                id="edit-cron-command"
                                value={cronForm.command}
                                onChange={(e) =>
                                    setCronForm((p) => ({ ...p, command: e.target.value }))
                                }
                                className="font-mono"
                            />
                            {errors?.command && <p className="text-destructive text-sm">{errors.command}</p>}
                        </div>
                        {showSitePicker ? (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Select
                                    value={
                                        cronForm.site_id === ''
                                            ? 'server'
                                            : String(cronForm.site_id)
                                    }
                                    onValueChange={(v) =>
                                        setCronForm((p) => ({
                                            ...p,
                                            site_id: v === 'server' ? '' : Number(v),
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Server (no site)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="server">Server (no site)</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.domain}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label>Site</Label>
                                <Input value={site!.domain} disabled readOnly />
                            </div>
                        )}
                        <div className="space-y-2">
                            <Label>Frequency</Label>
                            <Select
                                value={customCronFreq ? 'custom' : cronForm.frequency}
                                onValueChange={(v) => {
                                    if (v === 'custom') {
                                        setCustomCronFreq(true);
                                        setCronForm((p) => ({ ...p, frequency: '' }));
                                    } else {
                                        setCustomCronFreq(false);
                                        setCronForm((p) => ({ ...p, frequency: v }));
                                    }
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CRON_FREQUENCIES.map((f) => (
                                        <SelectItem key={f.value} value={f.value}>
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value="custom">Custom…</SelectItem>
                                </SelectContent>
                            </Select>
                            {customCronFreq && (
                                <Input
                                    value={cronForm.frequency}
                                    onChange={(e) =>
                                        setCronForm((p) => ({ ...p, frequency: e.target.value }))
                                    }
                                    placeholder="0 2 * * 1-5"
                                    className="font-mono"
                                    autoFocus
                                />
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-cron-user">User</Label>
                            <Input
                                id="edit-cron-user"
                                value={cronForm.user}
                                onChange={(e) =>
                                    setCronForm((p) => ({ ...p, user: e.target.value }))
                                }
                                className="font-mono"
                            />
                            {errors?.user && <p className="text-destructive text-sm">{errors.user}</p>}
                        </div>
                        <SheetFooter>
                            <Button type="button" variant="outline" onClick={() => setEditCronOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Saving…' : 'Save'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* Logs dialog */}
            <Dialog open={logsOpen} onOpenChange={setLogsOpen}>
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Worker logs {logsWorker ? `— ${logsWorker.name}` : ''}</DialogTitle>
                    </DialogHeader>
                    <div className="max-h-[60vh] overflow-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">
                        {logsLoading ? (
                            <div className="flex items-center gap-2 text-muted-foreground">
                                <Loader2Icon className="h-4 w-4 animate-spin" />
                                Loading logs…
                            </div>
                        ) : logsContent ? (
                            logsContent
                        ) : (
                            <span className="text-muted-foreground">No log output.</span>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleteWorkerOpen}
                onOpenChange={setDeleteWorkerOpen}
                title="Delete worker?"
                description={
                    workerToDelete
                        ? `This will remove the supervisor program for "${workerToDelete.name}". It cannot be undone.`
                        : 'This will remove the supervisor program. It cannot be undone.'
                }
                confirmLabel="Delete worker"
                onConfirm={confirmDeleteWorker}
                variant="destructive"
            />

            <ConfirmDialog
                open={deleteCronOpen}
                onOpenChange={setDeleteCronOpen}
                title="Delete cron job?"
                description={
                    cronToDelete
                        ? `This will remove the cron entry "${truncate(cronToDelete.command, 60)}". It cannot be undone.`
                        : 'This will remove the cron entry. It cannot be undone.'
                }
                confirmLabel="Delete cron job"
                onConfirm={confirmDeleteCron}
                variant="destructive"
            />
        </>
    );
}
