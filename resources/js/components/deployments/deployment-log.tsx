import { cn } from '@/lib/utils';
import { DeploymentLogLine } from '@/hooks/use-deployment-logs';
import { useEffect, useMemo, useRef } from 'react';
import { AnsiUp } from 'ansi_up';

const PLACEHOLDER_LOGS: DeploymentLogLine[] = [
    { type: 'info', message: '=> Warming up deployment workers' },
    { type: 'info', message: '=> Preparing to build site...' },
];

interface DeploymentLogProps {
    logs: DeploymentLogLine[];
    /** When true and logs are empty, show placeholder lines (Forge-style) */
    isDeploying?: boolean;
    className?: string;
}

const ansiUp = new AnsiUp();

export function DeploymentLog({ logs, isDeploying = false, className }: DeploymentLogProps) {
    const logEndRef = useRef<HTMLDivElement>(null);

    // Auto-scroll to bottom when new logs arrive
    useEffect(() => {
        logEndRef.current?.scrollIntoView({ behavior: 'smooth' });
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

    const displayLogs = logs.length > 0 ? logs : (isDeploying ? PLACEHOLDER_LOGS : []);

    const renderedLogs = useMemo(
        () =>
            displayLogs.map((log) => ({
                ...log,
                html: ansiUp.ansi_to_html(log.message),
            })),
        [displayLogs],
    );

    if (displayLogs.length === 0) {
        return (
            <div className={cn('flex items-center justify-center py-12 text-center', className)}>
                <p className="text-muted-foreground text-sm">No logs yet. Deployment will start shortly...</p>
            </div>
        );
    }

    return (
        <div className={cn('bg-muted font-mono text-sm', className)}>
            <div className="max-h-[600px] overflow-y-auto p-4">
                <div className="space-y-1">
                    {renderedLogs.map((log, index) => (
                        <div
                            key={index}
                            className={cn(
                                'whitespace-pre-wrap break-words [&_span]:text-inherit',
                                getLineColor(log.type),
                            )}
                            dangerouslySetInnerHTML={{ __html: log.html }}
                        />
                    ))}
                    <div ref={logEndRef} />
                </div>
            </div>
        </div>
    );
}
