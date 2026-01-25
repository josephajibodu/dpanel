import { cn } from '@/lib/utils';
import { DeploymentLogLine } from '@/hooks/use-deployment-logs';
import { useEffect, useRef } from 'react';

interface DeploymentLogProps {
    logs: DeploymentLogLine[];
    className?: string;
}

export function DeploymentLog({ logs, className }: DeploymentLogProps) {
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

    if (logs.length === 0) {
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
                    {logs.map((log, index) => (
                        <div
                            key={index}
                            className={cn('whitespace-pre-wrap break-words', getLineColor(log.type))}
                        >
                            {log.message}
                        </div>
                    ))}
                    <div ref={logEndRef} />
                </div>
            </div>
        </div>
    );
}
