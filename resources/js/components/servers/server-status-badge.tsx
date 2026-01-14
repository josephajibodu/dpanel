import { cn } from '@/lib/utils';
import { Loader2Icon } from 'lucide-react';

interface ServerStatusBadgeProps {
    status: string;
    statusLabel: string;
    statusColor: 'gray' | 'blue' | 'yellow' | 'green' | 'red' | 'orange';
    className?: string;
}

const colorClasses = {
    gray: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    green: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    red: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    orange: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
};

const pulsingStatuses = ['pending', 'creating', 'provisioning', 'deleting'];

export function ServerStatusBadge({
    status,
    statusLabel,
    statusColor,
    className,
}: ServerStatusBadgeProps) {
    const isPulsing = pulsingStatuses.includes(status);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                colorClasses[statusColor],
                className,
            )}
        >
            {isPulsing && <Loader2Icon className="h-3 w-3 animate-spin" />}
            {statusLabel}
        </span>
    );
}
