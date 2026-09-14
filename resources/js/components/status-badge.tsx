import { Loader2Icon } from 'lucide-react';

import { cn } from '@/lib/utils';

export type StatusColor =
    | 'gray'
    | 'blue'
    | 'teal'
    | 'yellow'
    | 'green'
    | 'red'
    | 'orange';

interface StatusBadgeProps {
    status: string;
    color?: StatusColor;
    className?: string;
    pulse?: boolean;
}

const colorClasses: Record<StatusColor, string> = {
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    teal: 'bg-teal-100 text-teal-700 dark:bg-teal-900/50 dark:text-teal-300',
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
    orange: 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300',
};

/** The single canonical status pill, used directly or via a domain wrapper
 *  (ServerStatusBadge, SiteStatusBadge, AutoStatusBadge). */
export function StatusBadge({
    status,
    color = 'gray',
    className,
    pulse = false,
}: StatusBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                colorClasses[color],
                className,
            )}
        >
            {pulse && <Loader2Icon className="h-3 w-3 animate-spin" />}
            {status}
        </span>
    );
}
