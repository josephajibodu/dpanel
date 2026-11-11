import { Head, Link, useForm } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    ArrowLeftIcon,
    CheckIcon,
    CircleNotchIcon,
    CopyIcon,
    XIcon,
} from '@phosphor-icons/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';

interface Props {
    server: { data: Server } | Server;
    authorized_keys_command: string;
}

type ConnectionTestState = 'idle' | 'testing' | 'success' | 'failed';

interface ConnectionTestResult {
    successful: boolean;
    message: string;
}

interface SetupStepProps {
    number: number;
    title: string;
    description: React.ReactNode;
    children: React.ReactNode;
}

/** One numbered step of the setup, separated from the next by a thin line. */
function SetupStep({ number, title, description, children }: SetupStepProps) {
    return (
        <section className="flex gap-4 p-5">
            <span
                aria-hidden
                className="flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium text-muted-foreground tabular-nums"
            >
                {number}
            </span>
            <div className="min-w-0 flex-1 space-y-4">
                <div className="space-y-0.5">
                    <h2 className="text-sm font-medium">{title}</h2>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {children}
            </div>
        </section>
    );
}

export default function ServerSetup({
    server: serverProp,
    authorized_keys_command,
}: Props) {
    const server =
        (serverProp as { data?: Server })?.data ?? (serverProp as Server);
    const teamPath = useTeamPath();
    const canProvision = usePermission()('manage_servers');
    const [copied, setCopied] = useState(false);
    const [testState, setTestState] = useState<ConnectionTestState>('idle');
    const [testResult, setTestResult] = useState<ConnectionTestResult | null>(
        null,
    );

    const provisionForm = useForm({});
    const testForm = useForm({});

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Setup', href: teamPath(`/servers/${server.id}/setup`) },
    ];

    useEcho<ConnectionTestResult>(
        `server.${server.id}`,
        '.server.connection.tested',
        (event) => {
            setTestResult(event);
            setTestState(event.successful ? 'success' : 'failed');
        },
    );

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

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={teamPath('/servers')}
                            aria-label="Back to servers"
                        >
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Connect {server.name}
                        </h1>
                        <p className="font-mono text-xs text-muted-foreground">
                            {server.ip_address}:{server.ssh_port}
                        </p>
                    </div>
                </div>

                <Card className="gap-0 divide-y py-0">
                    <SetupStep
                        number={1}
                        title="Add the FlitOps key"
                        description="Run this as root on a fresh Ubuntu 22.04 or 24.04 server. It lets FlitOps log in over SSH."
                    >
                        <div className="relative rounded-md border bg-muted">
                            <pre className="overflow-x-auto p-3 pr-12 font-mono text-xs leading-relaxed break-all whitespace-pre-wrap">
                                {authorized_keys_command}
                            </pre>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={handleCopy}
                                aria-label={copied ? 'Copied' : 'Copy command'}
                                className="absolute top-1.5 right-1.5 size-8"
                            >
                                {copied ? (
                                    <CheckIcon className="h-4 w-4 text-green-600 dark:text-green-400" />
                                ) : (
                                    <CopyIcon className="h-4 w-4" />
                                )}
                            </Button>
                        </div>
                    </SetupStep>

                    <SetupStep
                        number={2}
                        title="Test the connection"
                        description="Check that FlitOps can reach the server before provisioning starts."
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleTestConnection}
                                disabled={testState === 'testing'}
                            >
                                {testState === 'testing' && (
                                    <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                )}
                                {testState === 'testing'
                                    ? 'Testing…'
                                    : 'Test connection'}
                            </Button>

                            {testResult && (
                                <p
                                    role="status"
                                    className={cn(
                                        'flex items-start gap-1.5 text-sm',
                                        testResult.successful
                                            ? 'text-green-700 dark:text-green-400'
                                            : 'text-red-700 dark:text-red-400',
                                    )}
                                >
                                    {testResult.successful ? (
                                        <CheckIcon className="mt-0.5 h-4 w-4 shrink-0" />
                                    ) : (
                                        <XIcon className="mt-0.5 h-4 w-4 shrink-0" />
                                    )}
                                    {testResult.message}
                                </p>
                            )}
                        </div>
                    </SetupStep>

                    <SetupStep
                        number={3}
                        title="Start provisioning"
                        description="FlitOps installs the web server, PHP, your database, Redis and Supervisor, then shows progress as it goes."
                    >
                        {canProvision ? (
                            <div className="flex items-center gap-2">
                                <Button
                                    onClick={handleProvision}
                                    disabled={provisionForm.processing}
                                >
                                    {provisionForm.processing && (
                                        <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                    )}
                                    Start provisioning
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={teamPath('/servers')}>
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Ask an Owner or Admin to start provisioning.
                            </p>
                        )}
                    </SetupStep>
                </Card>
            </div>
        </AppLayout>
    );
}
