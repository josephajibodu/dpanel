import { router } from '@inertiajs/react';
import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useTeamPath } from '@/hooks/use-team-path';

type ConnectionState = 'idle' | 'connecting' | 'connected' | 'closed';

interface Props {
    serverId: number;
}

interface IssuedSession {
    gateway_url: string;
    token: string;
}

/**
 * An xterm.js terminal wired to the terminal gateway. Flitops issues a
 * single-use token, the browser hands it to the gateway in its first message,
 * then keystrokes go up as binary frames and shell output comes back the same
 * way.
 */
export function ServerTerminal({ serverId }: Props) {
    const teamPath = useTeamPath();
    const containerRef = useRef<HTMLDivElement>(null);
    const terminalRef = useRef<Terminal | null>(null);
    const fitRef = useRef<FitAddon | null>(null);
    const socketRef = useRef<WebSocket | null>(null);
    const [state, setState] = useState<ConnectionState>('idle');
    const [message, setMessage] = useState<string | null>(null);

    useEffect(() => {
        if (!containerRef.current) {
            return;
        }

        const terminal = new Terminal({
            cursorBlink: true,
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
            fontSize: 13,
        });
        const fit = new FitAddon();
        terminal.loadAddon(fit);
        terminal.open(containerRef.current);
        fit.fit();

        const encoder = new TextEncoder();
        const input = terminal.onData((data) => {
            const socket = socketRef.current;
            if (socket?.readyState === WebSocket.OPEN) {
                socket.send(encoder.encode(data));
            }
        });
        const resize = terminal.onResize(({ cols, rows }) => {
            const socket = socketRef.current;
            if (socket?.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ type: 'resize', cols, rows }));
            }
        });

        const observer = new ResizeObserver(() => fit.fit());
        observer.observe(containerRef.current);

        terminalRef.current = terminal;
        fitRef.current = fit;

        return () => {
            observer.disconnect();
            input.dispose();
            resize.dispose();
            socketRef.current?.close();
            terminal.dispose();
        };
    }, []);

    const connect = useCallback(async () => {
        const terminal = terminalRef.current;
        if (!terminal) {
            return;
        }

        setState('connecting');
        setMessage(null);

        const csrfToken =
            document
                .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';

        let session: IssuedSession;
        try {
            const response = await fetch(
                teamPath(`/servers/${serverId}/terminal-sessions`),
                {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                },
            );

            if (response.status === 423) {
                // The password confirmation lapsed; reloading sends the
                // user through the confirm screen and back here.
                router.reload();
                return;
            }

            const body = await response.json();
            if (!response.ok) {
                throw new Error(body.message ?? 'Could not open a terminal.');
            }
            session = body as IssuedSession;
        } catch (error) {
            setState('closed');
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'Could not open a terminal.',
            );
            return;
        }

        terminal.reset();
        fitRef.current?.fit();

        const socket = new WebSocket(session.gateway_url);
        socket.binaryType = 'arraybuffer';
        socketRef.current = socket;

        socket.onopen = () => {
            socket.send(
                JSON.stringify({
                    type: 'auth',
                    token: session.token,
                    cols: terminal.cols,
                    rows: terminal.rows,
                }),
            );
            setState('connected');
            terminal.focus();
        };
        socket.onmessage = (event: MessageEvent<ArrayBuffer>) => {
            terminal.write(new Uint8Array(event.data));
        };
        socket.onclose = (event) => {
            if (socketRef.current !== socket) {
                return;
            }
            socketRef.current = null;
            setState('closed');
            setMessage(
                event.reason ||
                    (event.code === 1000
                        ? 'Session closed.'
                        : 'Lost the connection to the terminal gateway.'),
            );
        };
    }, [serverId, teamPath]);

    const disconnect = () => {
        socketRef.current?.close(1000);
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-4">
                <p className="text-sm text-muted-foreground">
                    {state === 'idle' &&
                        'Opens a shell as the server user. The session is logged with your name.'}
                    {state === 'connecting' && 'Connecting…'}
                    {state === 'connected' && 'Connected.'}
                    {state === 'closed' && message}
                </p>
                {state === 'connected' ? (
                    <Button variant="outline" onClick={disconnect}>
                        Disconnect
                    </Button>
                ) : (
                    <Button onClick={connect} disabled={state === 'connecting'}>
                        {state === 'closed' ? 'Reconnect' : 'Connect'}
                    </Button>
                )}
            </div>
            <div className="h-[60vh] min-h-80 overflow-hidden rounded-lg border bg-black p-2">
                <div ref={containerRef} className="h-full w-full" />
            </div>
        </div>
    );
}
