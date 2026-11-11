import { CircleNotchIcon } from '@phosphor-icons/react';

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
    gray: 'bg-foreground/[0.06] text-muted-foreground',
    blue: 'bg-[#d5ebff] text-[#003a5a] dark:bg-blue-900/50 dark:text-blue-300',
    teal: 'bg-[#c9f5ef] text-[#0b4a45] dark:bg-teal-900/50 dark:text-teal-300',
    yellow: 'bg-[#ffef9d] text-[#4f4700] dark:bg-yellow-900/50 dark:text-yellow-300',
    green: 'bg-[#affebf] text-[#014b40] dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-[#fed1d7] text-[#8e0b21] dark:bg-red-900/50 dark:text-red-300',
    orange: 'bg-[#ffd6a4] text-[#5e4200] dark:bg-orange-900/50 dark:text-orange-300',
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
                'inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs font-medium whitespace-nowrap capitalize',
                colorClasses[color],
                className,
            )}
        >
            {pulse && <CircleNotchIcon className="h-3 w-3 animate-spin" />}
            {status}
        </span>
    );
}
