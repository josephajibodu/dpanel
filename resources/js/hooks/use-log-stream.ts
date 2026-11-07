import { useEcho } from '@laravel/echo-react';
import { useState } from 'react';

export interface LogLine {
    id?: number;
    type: 'output' | 'error' | 'info' | 'success';
    message: string;
    timestamp?: string;
}

interface LogOutputEvent {
    line: string;
    type?: LogLine['type'];
    timestamp?: string;
}

/**
 * Streams log lines broadcast on a channel, adding each one to the end of the
 * lines the page started with. Lines arrive one at a time, so they are
 * appended here instead of reloading the whole log as a live prop.
 *
 * When the page later receives a longer list from the server, such as after a
 * retry that keeps the page mounted, that list replaces the streamed one.
 */
export function useLogStream(
    channel: string,
    event: string,
    initialLogs: LogLine[] = [],
): LogLine[] {
    const [logs, setLogs] = useState<LogLine[]>(initialLogs);

    if (initialLogs.length > logs.length) {
        setLogs(initialLogs);
    }

    useEcho<LogOutputEvent>(channel, event, (output) => {
        setLogs((previous) => [
            ...previous,
            {
                type: output.type || 'output',
                message: output.line,
                timestamp: output.timestamp,
            },
        ]);
    });

    return logs;
}
