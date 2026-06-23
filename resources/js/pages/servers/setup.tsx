import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { Head, Link, useForm } from '@inertiajs/react';
import { CheckCircleIcon, CheckIcon, ClipboardIcon, Loader2Icon, ServerIcon, TriangleAlertIcon, XCircleIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    server: { data: Server } | Server;
    authorized_keys_command: string;
}

type ConnectionTestState = 'idle' | 'testing' | 'success' | 'failed';

interface ConnectionTestResult {
    successful: boolean;
    message: string;
}

export default function ServerSetup({ server: serverProp, authorized_keys_command }: Props) {
    const server = (serverProp as { data?: Server })?.data ?? (serverProp as Server);
    const teamPath = useTeamPath();
    const [copied, setCopied] = useState(false);
    const [testState, setTestState] = useState<ConnectionTestState>('idle');
    const [testResult, setTestResult] = useState<ConnectionTestResult | null>(null);
    const channelRef = useRef<any>(null);

    const provisionForm = useForm({});
    const testForm = useForm({});

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Setup', href: teamPath(`/servers/${server.id}/setup`) },
    ];

    useEffect(() => {
        if (!window.Echo) {
            return;
        }

        const channel = window.Echo.private(`server.${server.id}`);
        channelRef.current = channel;

        channel.listen('.server.connection.tested', (event: ConnectionTestResult) => {
            setTestResult(event);
            setTestState(event.successful ? 'success' : 'failed');
        });

        return () => {
            channel.stopListening('.server.connection.tested');
            window.Echo.leave(`server.${server.id}`);
        };
    }, [server.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleCopy = () => {
        navigator.clipboard.writeText(authorized_keys_command).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    const handleTestConnection = () => {
        setTestState('testing');
        setTestResult(null);
        testForm.post(teamPath(`/servers/${server.id}/test-connection`), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleProvision = () => {
        provisionForm.post(teamPath(`/servers/${server.id}/provision`));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Setup — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Connect your server</h1>
                    <p className="text-muted-foreground text-sm">
                        Add FlitOps to <span className="font-medium">{server.name}</span> ({server.ip_address}) before starting
                        provisioning.
                    </p>
                </div>

                <div className="mx-auto w-full max-w-2xl space-y-6">
                    <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                        <TriangleAlertIcon className="mt-0.5 h-4 w-4 shrink-0" />
                        <p>Your server must have a fresh, unused installation of Ubuntu 22.04, 24.04, or 26.04 with root SSH access enabled.</p>
                    </div>

                    {/* Step 1 — Add key */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ServerIcon className="h-5 w-5" />
                                Step 1 — Add FlitOps to your server
                            </CardTitle>
                            <CardDescription>
                                Run this command as root on <strong>{server.ip_address}</strong> (port {server.ssh_port}).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground text-sm">Run as root user</span>
                                <button
                                    type="button"
                                    onClick={handleCopy}
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                                >
                                    {copied ? (
                                        <>
                                            <CheckIcon className="h-3.5 w-3.5" />
                                            Copied
                                        </>
                                    ) : (
                                        <>
                                            <ClipboardIcon className="h-3.5 w-3.5" />
                                            Copy
                                        </>
                                    )}
                                </button>
                            </div>
                            <textarea
                                readOnly
                                value={authorized_keys_command}
                                rows={3}
                                className="bg-muted text-muted-foreground w-full rounded-md border px-3 py-2 font-mono text-xs leading-relaxed focus:outline-none"
                            />
                        </CardContent>
                    </Card>

                    {/* Step 2 — Test connection */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Step 2 — Test connection</CardTitle>
                            <CardDescription>
                                Verify FlitOps can reach your server before starting the full provisioning run.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Button
                                variant="outline"
                                onClick={handleTestConnection}
                                disabled={testState === 'testing'}
                            >
                                {testState === 'testing' && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                {testState === 'testing' ? 'Testing connection…' : 'Test connection'}
                            </Button>

                            {testState === 'success' && testResult && (
                                <div className="flex items-start gap-2 text-sm text-green-700 dark:text-green-400">
                                    <CheckCircleIcon className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{testResult.message}</span>
                                </div>
                            )}

                            {testState === 'failed' && testResult && (
                                <div className="flex items-start gap-2 text-sm text-red-700 dark:text-red-400">
                                    <XCircleIcon className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{testResult.message}</span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 3 — Provision */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Step 3 — Start provisioning</CardTitle>
                            <CardDescription>
                                FlitOps will install Nginx, PHP, your database, Redis, and Supervisor on your server.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-3">
                                <Button onClick={handleProvision} disabled={provisionForm.processing}>
                                    {provisionForm.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                    Start Provisioning
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={teamPath('/servers')}>Cancel</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
