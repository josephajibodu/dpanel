import { AnsiUp } from 'ansi_up';
import { useEffect, useMemo, useRef } from 'react';

import { type LogLine } from '@/hooks/use-log-stream';
import { cn } from '@/lib/utils';

const PLACEHOLDER_LOGS: LogLine[] = [
    { type: 'info', message: '=> Warming up deployment workers' },
    { type: 'info', message: '=> Preparing to build site...' },
];

interface DeploymentLogProps {
    logs: LogLine[];
    /** When true and logs are empty, show placeholder lines (Forge-style) */
    isDeploying?: boolean;
    className?: string;
}

const ansiUp = new AnsiUp();

export function DeploymentLog({
    logs,
    isDeploying = false,
    className,
}: DeploymentLogProps) {
    const scrollRef = useRef<HTMLDivElement>(null);

    // Keep the newest line in view by scrolling the log box itself, not the page.
    useEffect(() => {
        const box = scrollRef.current;

        if (box) {
            box.scrollTop = box.scrollHeight;
        }
    }, [logs]);

    const getLineColor = (type: string) => {
        switch (type) {
            case 'error':
                return 'text-red-600 dark:text-red-400';
            case 'info':
                return 'text-blue-600 dark:text-blue-400';
            case 'success':
                return 'text-green-600 dark:text-green-400';
            default:
                return 'text-foreground';
        }
    };

    const renderedLogs = useMemo(() => {
        const displayLogs =
            logs.length > 0 ? logs : isDeploying ? PLACEHOLDER_LOGS : [];

        return displayLogs.map((log) => ({
            ...log,
            html: ansiUp.ansi_to_html(log.message),
        }));
    }, [logs, isDeploying]);

    if (renderedLogs.length === 0) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center py-12 text-center',
                    className,
                )}
            >
                <p className="text-sm text-muted-foreground">
                    No logs yet. Deployment will start shortly...
                </p>
            </div>
        );
    }

    return (
        <div
            className={cn(
                'bg-muted font-log text-[13px] leading-relaxed',
                className,
            )}
        >
            <div ref={scrollRef} className="max-h-[600px] overflow-y-auto p-4">
                <div className="space-y-0.5">
                    {renderedLogs.map((log, index) => (
                        <div
                            key={index}
                            className={cn(
                                'font-log break-words whitespace-pre-wrap [&_span]:text-inherit',
                                getLineColor(log.type),
                            )}
                            dangerouslySetInnerHTML={{ __html: log.html }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
