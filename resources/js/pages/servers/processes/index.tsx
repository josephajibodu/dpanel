import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
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
import { getServerSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import type {
    CronJob,
    ProcessSite,
    Server,
    Worker,
} from '@/types/server';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ClockIcon,
    Loader2Icon,
    PencilIcon,
    PlayIcon,
    PlusIcon,
    PowerIcon,
    PowerOffIcon,
    RefreshCwIcon,
    Trash2Icon,
    FileTextIcon,
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

interface Props {
    server: { data: Server } | Server;
    serverIsReady: boolean;
    workers: { data: Worker[] };
    cronJobs: { data: CronJob[] };
    sites: ProcessSite[];
}

function truncate(str: string, max: number) {
    if (str.length <= max) return str;
    return str.slice(0, max) + '…';
}

export default function ServerProcessesIndex({
    server: serverProp,
    serverIsReady,
    workers,
    cronJobs,
    sites,
}: Props) {
    const server =
        serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const workerList = workers?.data ?? [];
    const cronList = cronJobs?.data ?? [];
    const { errors } = usePage().props as { errors?: Record<string, string> };

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
    const [isDeletingWorker, setIsDeletingWorker] = useState(false);
    const [isDeletingCron, setIsDeletingCron] = useState(false);

    const [workerForm, setWorkerForm] = useState({
        name: '',
        command: '',
        site_id: '' as string | number,
        user: 'deploy',
        numprocs: 1,
        auto_start: true,
        auto_restart: true,
    });
    const [cronForm, setCronForm] = useState({
        command: '',
        site_id: '' as string | number,
        user: 'deploy',
        frequency: '* * * * *',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Processes', href: `/servers/${server.id}/processes` },
    ];

    const fetchLogs = useCallback(
        (w: Worker) => {
            if (!server?.id) return;
            setLogsWorker(w);
            setLogsOpen(true);
            setLogsContent('');
            setLogsLoading(true);
            fetch(`/servers/${server.id}/workers/${w.id}/logs`, {
                headers: { Accept: 'text/plain', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((r) => r.text())
                .then((text) => setLogsContent(text))
                .catch(() => setLogsContent('Failed to load logs.'))
                .finally(() => setLogsLoading(false));
        },
        [server?.id],
    );

    const handleCreateWorker = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id) return;
        setIsSubmitting(true);
        const siteId =
            workerForm.site_id === '' || workerForm.site_id === 'server'
                ? null
                : Number(workerForm.site_id);
        router.post(`/servers/${server.id}/workers`, {
            name: workerForm.name,
            command: workerForm.command,
            site_id: siteId,
            user: workerForm.user,
            numprocs: workerForm.numprocs,
            auto_start: workerForm.auto_start,
            auto_restart: workerForm.auto_restart,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setCreateWorkerOpen(false);
                setWorkerForm({
                    name: '',
                    command: '',
                    site_id: '',
                    user: 'deploy',
                    numprocs: 1,
                    auto_start: true,
                    auto_restart: true,
                });
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleUpdateWorker = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !workerToEdit) return;
        setIsSubmitting(true);
        const siteId =
            workerForm.site_id === '' || workerForm.site_id === 'server'
                ? null
                : Number(workerForm.site_id);
        router.put(`/servers/${server.id}/workers/${workerToEdit.id}`, {
            name: workerForm.name,
            command: workerForm.command,
            site_id: siteId,
            user: workerForm.user,
            numprocs: workerForm.numprocs,
            auto_start: workerForm.auto_start,
            auto_restart: workerForm.auto_restart,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditWorkerOpen(false);
                setWorkerToEdit(null);
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const openEditWorker = (w: Worker) => {
        setWorkerToEdit(w);
        setWorkerForm({
            name: w.name,
            command: w.command,
            site_id: w.site_id ?? 'server',
            user: w.user,
            numprocs: w.numprocs,
            auto_start: w.auto_start,
            auto_restart: w.auto_restart,
        });
        setEditWorkerOpen(true);
    };

    const confirmDeleteWorker = () => {
        if (!server?.id || !workerToDelete) return;
        setIsDeletingWorker(true);
        router.delete(
            `/servers/${server.id}/workers/${workerToDelete.id}`,
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsDeletingWorker(false);
                    setDeleteWorkerOpen(false);
                    setWorkerToDelete(null);
                },
            },
        );
    };

    const handleCreateCron = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id) return;
        setIsSubmitting(true);
        const siteId =
            cronForm.site_id === '' || cronForm.site_id === 'server'
                ? null
                : Number(cronForm.site_id);
        router.post(`/servers/${server.id}/cron-jobs`, {
            command: cronForm.command,
            site_id: siteId,
            user: cronForm.user,
            frequency: cronForm.frequency,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setCreateCronOpen(false);
                setCronForm({
                    command: '',
                    site_id: '',
                    user: 'deploy',
                    frequency: '* * * * *',
                });
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleUpdateCron = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !cronToEdit) return;
        setIsSubmitting(true);
        const siteId =
            cronForm.site_id === '' || cronForm.site_id === 'server'
                ? null
                : Number(cronForm.site_id);
        router.put(`/servers/${server.id}/cron-jobs/${cronToEdit.id}`, {
            command: cronForm.command,
            site_id: siteId,
            user: cronForm.user,
            frequency: cronForm.frequency,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditCronOpen(false);
                setCronToEdit(null);
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const openEditCron = (c: CronJob) => {
        setCronToEdit(c);
        setCronForm({
            command: c.command,
            site_id: c.site_id ?? 'server',
            user: c.user,
            frequency: c.frequency,
        });
        setEditCronOpen(true);
    };

    const confirmDeleteCron = () => {
        if (!server?.id || !cronToDelete) return;
        setIsDeletingCron(true);
        router.delete(
            `/servers/${server.id}/cron-jobs/${cronToDelete.id}`,
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsDeletingCron(false);
                    setDeleteCronOpen(false);
                    setCronToDelete(null);
                },
            },
        );
    };

    const frequencyLabel = (value: string) =>
        CRON_FREQUENCIES.find((f) => f.value === value)?.label ?? value;

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(server.id)}
        >
            <Head title={`Processes - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Processes
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Workers and cron jobs on {server.name}.
                        </p>
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
                                    <CardDescription>
                                        Supervisor-style workers on this server.
                                    </CardDescription>
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
                                        <TableHead>Site</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Numprocs</TableHead>
                                        <TableHead className="w-[220px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {workerList.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="h-24 text-center text-muted-foreground text-sm"
                                            >
                                                No workers yet.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        workerList.map((w) => (
                                            <TableRow key={w.id}>
                                                <TableCell className="font-medium">
                                                    {w.name}
                                                </TableCell>
                                                <TableCell className="max-w-[200px] truncate font-mono text-sm">
                                                    {truncate(w.command, 40)}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm font-mono">
                                                    {w.user}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {w.site?.domain ?? 'Server'}
                                                </TableCell>
                                                <TableCell>
                                                    <span
                                                        className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ${
                                                            w.status === 'active'
                                                                ? 'bg-green-500/15 text-green-700 dark:text-green-400'
                                                                : 'bg-muted text-muted-foreground'
                                                        }`}
                                                    >
                                                        {w.status !== 'active' && <Loader2Icon className="h-3 w-3 animate-spin" />}
                                                        {w.status}
                                                    </span>
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
                                                            onClick={() =>
                                                                openEditWorker(w)
                                                            }
                                                            aria-label="Edit worker"
                                                        >
                                                            <PencilIcon className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            onClick={() =>
                                                                fetchLogs(w)
                                                            }
                                                            aria-label="View logs"
                                                        >
                                                            <FileTextIcon className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/servers/${server.id}/workers/${w.id}/start`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
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
                                                            onClick={() =>
                                                                router.post(
                                                                    `/servers/${server.id}/workers/${w.id}/stop`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
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
                                                            onClick={() =>
                                                                router.post(
                                                                    `/servers/${server.id}/workers/${w.id}/restart`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
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
                                        ))
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
                                    <CardDescription>
                                        Scheduled cron jobs on this server.
                                    </CardDescription>
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
                                        <TableHead>Site</TableHead>
                                        <TableHead>Enabled</TableHead>
                                        <TableHead className="w-[120px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {cronList.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center text-muted-foreground text-sm"
                                            >
                                                No cron jobs yet.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        cronList.map((c) => (
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
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {c.site?.domain ?? 'Server'}
                                                </TableCell>
                                                <TableCell>
                                                    <span
                                                        className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ${
                                                            c.hidden
                                                                ? 'bg-muted text-muted-foreground'
                                                                : 'bg-green-500/15 text-green-700 dark:text-green-400'
                                                        }`}
                                                    >
                                                        {c.hidden ? 'Disabled' : 'Enabled'}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            onClick={() =>
                                                                openEditCron(c)
                                                            }
                                                            aria-label="Edit cron job"
                                                        >
                                                            <PencilIcon className="h-4 w-4" />
                                                        </Button>
                                                        {c.hidden ? (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="h-8"
                                                                onClick={() =>
                                                                    router.visit(
                                                                        `/servers/${server.id}/cron-jobs/${c.id}/enable`,
                                                                        {
                                                                            method: 'patch',
                                                                            preserveScroll: true,
                                                                        },
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
                                                                        `/servers/${server.id}/cron-jobs/${c.id}/disable`,
                                                                        {
                                                                            method: 'patch',
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Disable
                                                            </Button>
                                                        )}
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
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
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Create worker drawer */}
            <Sheet open={createWorkerOpen} onOpenChange={setCreateWorkerOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Create worker</SheetTitle>
                    </SheetHeader>
                    <form
                        onSubmit={handleCreateWorker}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="worker-name">Name</Label>
                            <Input
                                id="worker-name"
                                value={workerForm.name}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                placeholder="worker-1"
                                autoComplete="off"
                            />
                            {errors?.name && (
                                <p className="text-destructive text-sm">
                                    {errors.name}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="worker-command">Command</Label>
                            <Input
                                id="worker-command"
                                value={workerForm.command}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        command: e.target.value,
                                    }))
                                }
                                placeholder="php artisan queue:work"
                                className="font-mono"
                            />
                            <p className="text-muted-foreground text-xs">
                                <code className="php">php</code> will use default
                                PHP CLI. For a specific version use a path like{' '}
                                <code className="font-mono">
                                    /usr/bin/php8.4
                                </code>
                                .
                            </p>
                            {errors?.command && (
                                <p className="text-destructive text-sm">
                                    {errors.command}
                                </p>
                            )}
                        </div>
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
                                        site_id: v === 'server' ? '' : v,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Server (no site)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="server">
                                        Server (no site)
                                    </SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.domain}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="worker-user">User</Label>
                            <Input
                                id="worker-user"
                                value={workerForm.user}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        user: e.target.value,
                                    }))
                                }
                                placeholder="deploy"
                                className="font-mono"
                            />
                            {errors?.user && (
                                <p className="text-destructive text-sm">
                                    {errors.user}
                                </p>
                            )}
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
                                        numprocs: Math.max(
                                            1,
                                            parseInt(e.target.value, 10) || 1,
                                        ),
                                    }))
                                }
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="worker-auto-start"
                                checked={workerForm.auto_start}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        auto_start: checked === true,
                                    }))
                                }
                            />
                            <Label
                                htmlFor="worker-auto-start"
                                className="font-normal"
                            >
                                Auto start
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="worker-auto-restart"
                                checked={workerForm.auto_restart}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        auto_restart: checked === true,
                                    }))
                                }
                            />
                            <Label
                                htmlFor="worker-auto-restart"
                                className="font-normal"
                            >
                                Auto restart
                            </Label>
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateWorkerOpen(false)}
                            >
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
                        <SheetTitle>
                            Edit worker {workerToEdit?.name}
                        </SheetTitle>
                    </SheetHeader>
                    <form
                        onSubmit={handleUpdateWorker}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-name">Name</Label>
                            <Input
                                id="edit-worker-name"
                                value={workerForm.name}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                autoComplete="off"
                            />
                            {errors?.name && (
                                <p className="text-destructive text-sm">
                                    {errors.name}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-command">Command</Label>
                            <Input
                                id="edit-worker-command"
                                value={workerForm.command}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        command: e.target.value,
                                    }))
                                }
                                className="font-mono"
                            />
                            {errors?.command && (
                                <p className="text-destructive text-sm">
                                    {errors.command}
                                </p>
                            )}
                        </div>
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
                                        site_id: v === 'server' ? '' : v,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Server (no site)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="server">
                                        Server (no site)
                                    </SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.domain}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-user">User</Label>
                            <Input
                                id="edit-worker-user"
                                value={workerForm.user}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        user: e.target.value,
                                    }))
                                }
                                className="font-mono"
                            />
                            {errors?.user && (
                                <p className="text-destructive text-sm">
                                    {errors.user}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-worker-numprocs">
                                Numprocs
                            </Label>
                            <Input
                                id="edit-worker-numprocs"
                                type="number"
                                min={1}
                                max={64}
                                value={workerForm.numprocs}
                                onChange={(e) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        numprocs: Math.max(
                                            1,
                                            parseInt(e.target.value, 10) || 1,
                                        ),
                                    }))
                                }
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="edit-worker-auto-start"
                                checked={workerForm.auto_start}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        auto_start: checked === true,
                                    }))
                                }
                            />
                            <Label
                                htmlFor="edit-worker-auto-start"
                                className="font-normal"
                            >
                                Auto start
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="edit-worker-auto-restart"
                                checked={workerForm.auto_restart}
                                onCheckedChange={(checked) =>
                                    setWorkerForm((p) => ({
                                        ...p,
                                        auto_restart: checked === true,
                                    }))
                                }
                            />
                            <Label
                                htmlFor="edit-worker-auto-restart"
                                className="font-normal"
                            >
                                Auto restart
                            </Label>
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditWorkerOpen(false)}
                            >
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
                    <form
                        onSubmit={handleCreateCron}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="cron-command">Command</Label>
                            <Input
                                id="cron-command"
                                value={cronForm.command}
                                onChange={(e) =>
                                    setCronForm((p) => ({
                                        ...p,
                                        command: e.target.value,
                                    }))
                                }
                                placeholder="php artisan schedule:run"
                                className="font-mono"
                            />
                            {errors?.command && (
                                <p className="text-destructive text-sm">
                                    {errors.command}
                                </p>
                            )}
                        </div>
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
                                        site_id: v === 'server' ? '' : v,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Server (no site)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="server">
                                        Server (no site)
                                    </SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.domain}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Frequency</Label>
                            <Select
                                value={cronForm.frequency}
                                onValueChange={(v) =>
                                    setCronForm((p) => ({
                                        ...p,
                                        frequency: v,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CRON_FREQUENCIES.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={f.value}
                                        >
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors?.frequency && (
                                <p className="text-destructive text-sm">
                                    {errors.frequency}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="cron-user">User</Label>
                            <Input
                                id="cron-user"
                                value={cronForm.user}
                                onChange={(e) =>
                                    setCronForm((p) => ({
                                        ...p,
                                        user: e.target.value,
                                    }))
                                }
                                placeholder="deploy"
                                className="font-mono"
                            />
                            {errors?.user && (
                                <p className="text-destructive text-sm">
                                    {errors.user}
                                </p>
                            )}
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateCronOpen(false)}
                            >
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
            <Sheet open={editCronOpen} onOpenChange={setEditCronOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Edit cron job</SheetTitle>
                    </SheetHeader>
                    <form
                        onSubmit={handleUpdateCron}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="edit-cron-command">Command</Label>
                            <Input
                                id="edit-cron-command"
                                value={cronForm.command}
                                onChange={(e) =>
                                    setCronForm((p) => ({
                                        ...p,
                                        command: e.target.value,
                                    }))
                                }
                                className="font-mono"
                            />
                            {errors?.command && (
                                <p className="text-destructive text-sm">
                                    {errors.command}
                                </p>
                            )}
                        </div>
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
                                        site_id: v === 'server' ? '' : v,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Server (no site)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="server">
                                        Server (no site)
                                    </SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.domain}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Frequency</Label>
                            <Select
                                value={cronForm.frequency}
                                onValueChange={(v) =>
                                    setCronForm((p) => ({
                                        ...p,
                                        frequency: v,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CRON_FREQUENCIES.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={f.value}
                                        >
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-cron-user">User</Label>
                            <Input
                                id="edit-cron-user"
                                value={cronForm.user}
                                onChange={(e) =>
                                    setCronForm((p) => ({
                                        ...p,
                                        user: e.target.value,
                                    }))
                                }
                                className="font-mono"
                            />
                            {errors?.user && (
                                <p className="text-destructive text-sm">
                                    {errors.user}
                                </p>
                            )}
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditCronOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Saving…' : 'Save'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* View logs dialog */}
            <Dialog open={logsOpen} onOpenChange={setLogsOpen}>
                <DialogContent className="max-h-[80vh] max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Logs — {logsWorker?.name ?? 'Worker'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="min-h-[200px] overflow-auto rounded-md border bg-muted/30 p-3">
                        {logsLoading ? (
                            <p className="text-muted-foreground text-sm">
                                Loading…
                            </p>
                        ) : (
                            <pre className="whitespace-pre-wrap break-words font-mono text-xs">
                                {logsContent || 'No log content.'}
                            </pre>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleteWorkerOpen}
                onOpenChange={setDeleteWorkerOpen}
                title="Delete worker"
                description={`Are you sure you want to remove the worker "${workerToDelete?.name}"?`}
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDeleteWorker}
                loading={isDeletingWorker}
            />

            <ConfirmDialog
                open={deleteCronOpen}
                onOpenChange={setDeleteCronOpen}
                title="Delete cron job"
                description="Are you sure you want to remove this cron job?"
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDeleteCron}
                loading={isDeletingCron}
            />
        </AppLayout>
    );
}
