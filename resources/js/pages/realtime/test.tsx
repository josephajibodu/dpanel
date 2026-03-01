import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Loader2Icon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    channel: string;
    user: {
        id: number;
        name: string;
    };
    reverb: {
        host: string;
        port: number;
        scheme: string;
    };
}

interface DiagnosticEventPayload {
    event_id: string;
    message: string;
    sent_at: string;
    user_id: number;
}

type ConnectionStatus = 'idle' | 'connecting' | 'connected' | 'disconnected' | 'error';
type AuthorizationStatus = 'unknown' | 'authorizing' | 'authorized' | 'failed';

export default function RealtimeTest({ channel, user, reverb }: Props) {
    const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('idle');
    const [authorizationStatus, setAuthorizationStatus] = useState<AuthorizationStatus>('unknown');
    const [lastError, setLastError] = useState<string | null>(null);
    const [messages, setMessages] = useState<DiagnosticEventPayload[]>([]);
    const [messageToSend, setMessageToSend] = useState('Hello from realtime diagnostics');
    const [isSending, setIsSending] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Realtime test', href: '/realtime/test' },
    ];

    const statusBadgeClasses = useMemo(() => {
        return {
            connected: 'bg-green-500/15 text-green-700 dark:text-green-300',
            connecting: 'bg-yellow-500/15 text-yellow-700 dark:text-yellow-300',
            disconnected: 'bg-muted text-muted-foreground',
            error: 'bg-red-500/15 text-red-700 dark:text-red-300',
            idle: 'bg-muted text-muted-foreground',
        };
    }, []);

    useEffect(() => {
        if (!window.Echo) {
            setConnectionStatus('error');
            setAuthorizationStatus('failed');
            setLastError('Echo is not initialized on window.');
            return;
        }

        setConnectionStatus('connecting');
        setAuthorizationStatus('authorizing');

        const pusherConnection = window.Echo.connector?.pusher?.connection;
        const channelInstance = window.Echo.private(channel);

        const onConnected = () => {
            setConnectionStatus('connected');
            setLastError(null);
        };
        const onDisconnected = () => {
            setConnectionStatus('disconnected');
        };
        const onError = (error: unknown) => {
            setConnectionStatus('error');
            setLastError(JSON.stringify(error));
        };
        const onStateChange = (states: { previous: string; current: string }) => {
            if (states.current === 'connected') {
                setConnectionStatus('connected');
            }
            if (states.current === 'disconnected') {
                setConnectionStatus('disconnected');
            }
        };

        pusherConnection?.bind('connected', onConnected);
        pusherConnection?.bind('disconnected', onDisconnected);
        pusherConnection?.bind('error', onError);
        pusherConnection?.bind('state_change', onStateChange);

        channelInstance.subscribed(() => {
            setAuthorizationStatus('authorized');
            setLastError(null);
        });

        channelInstance.error((error: unknown) => {
            setAuthorizationStatus('failed');
            setLastError(JSON.stringify(error));
        });

        channelInstance.listen('.realtime.diagnostic.message', (event: DiagnosticEventPayload) => {
            setMessages((prev) => [...prev, event]);
        });

        return () => {
            channelInstance.stopListening('.realtime.diagnostic.message');
            window.Echo.leave(channel);

            pusherConnection?.unbind('connected', onConnected);
            pusherConnection?.unbind('disconnected', onDisconnected);
            pusherConnection?.unbind('error', onError);
            pusherConnection?.unbind('state_change', onStateChange);
        };
    }, [channel]);

    async function triggerMessage(): Promise<void> {
        setIsSending(true);
        setLastError(null);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const response = await fetch('/realtime/test/trigger', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    message: messageToSend,
                }),
            });

            if (!response.ok) {
                throw new Error(`Trigger failed with status ${response.status}`);
            }
        } catch (error) {
            setLastError(error instanceof Error ? error.message : 'Failed to trigger realtime message');
        } finally {
            setIsSending(false);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Realtime Diagnostics" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Realtime diagnostics</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Private-channel Reverb test harness for {user.name}.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Connection</CardTitle>
                            <CardDescription>WebSocket and private channel authorization status.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Socket</span>
                                    <span className={`rounded px-2 py-1 text-xs font-medium ${statusBadgeClasses[connectionStatus]}`}>
                                        {connectionStatus}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Private auth</span>
                                    <span className="rounded bg-muted px-2 py-1 text-xs font-medium text-foreground">
                                        {authorizationStatus}
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-2 border-t pt-4 text-xs">
                                <p>
                                    <span className="text-muted-foreground">Channel:</span> <code>{channel}</code>
                                </p>
                                <p>
                                    <span className="text-muted-foreground">Endpoint:</span>{' '}
                                    <code>{reverb.scheme}://{reverb.host}:{reverb.port}</code>
                                </p>
                            </div>

                            {lastError && (
                                <div className="rounded border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-700 dark:text-red-300">
                                    {lastError}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Trigger test event</CardTitle>
                            <CardDescription>Broadcast an immediate diagnostic event to your private channel.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="space-y-2">
                                <Label htmlFor="message">Message</Label>
                                <Input
                                    id="message"
                                    value={messageToSend}
                                    onChange={(event) => setMessageToSend(event.target.value)}
                                    placeholder="Type a test message..."
                                />
                            </div>
                            <Button onClick={triggerMessage} disabled={isSending || messageToSend.trim().length === 0}>
                                {isSending && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                Send diagnostic message
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Incoming events</CardTitle>
                        <CardDescription>Live stream of received private-channel diagnostic events.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {messages.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No events received yet. Trigger one from the panel above.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {messages.map((event) => (
                                    <div key={event.event_id} className="rounded border bg-muted/30 p-3 text-sm">
                                        <p className="font-medium">{event.message}</p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            event_id={event.event_id} · sent_at={event.sent_at} · user_id={event.user_id}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
