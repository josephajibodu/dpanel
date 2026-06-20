import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const STATUS_COLORS: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
    ready: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
    enabled: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
    installed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
    installing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    creating: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    pending: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300',
    stopped: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300',
    disabled: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    error: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    deleting: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
};

const PULSING_STATUSES = new Set(['installing', 'creating', 'pending', 'deleting']);

interface StatusBadgeProps {
    status: string;
    label?: string;
}

export function StatusBadge({ status, label }: StatusBadgeProps) {
    const colorClass = STATUS_COLORS[status] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
    const isPulsing = PULSING_STATUSES.has(status);
    const displayLabel = label ?? status.charAt(0).toUpperCase() + status.slice(1);

    return (
        <Badge variant="outline" className={cn('border-transparent font-medium', colorClass)}>
            {isPulsing && <span className="mr-1.5 h-2 w-2 animate-pulse rounded-full bg-current" />}
            {displayLabel}
        </Badge>
    );
}
