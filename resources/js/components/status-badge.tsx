import { cn } from '@/lib/utils';

type StatusColor = 'gray' | 'blue' | 'yellow' | 'green' | 'red' | 'orange';

interface StatusBadgeProps {
    status: string;
    color?: StatusColor;
    className?: string;
    pulse?: boolean;
}

const colorClasses: Record<StatusColor, string> = {
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
    orange: 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300',
};

const pulseColorClasses: Record<StatusColor, string> = {
    gray: 'bg-gray-500',
    blue: 'bg-blue-500',
    yellow: 'bg-yellow-500',
    green: 'bg-green-500',
    red: 'bg-red-500',
    orange: 'bg-orange-500',
};

export function StatusBadge({ status, color = 'gray', className, pulse = false }: StatusBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                colorClasses[color],
                className,
            )}
        >
            {pulse && (
                <span className="relative flex h-2 w-2">
                    <span
                        className={cn(
                            'absolute inline-flex h-full w-full animate-ping rounded-full opacity-75',
                            pulseColorClasses[color],
                        )}
                    />
                    <span className={cn('relative inline-flex h-2 w-2 rounded-full', pulseColorClasses[color])} />
                </span>
            )}
            {status}
        </span>
    );
}
