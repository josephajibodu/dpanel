import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface SiteStatusBadgeProps {
    status: string;
    statusLabel: string;
    statusColor: string;
}

export function SiteStatusBadge({ status, statusLabel, statusColor }: SiteStatusBadgeProps) {
    const colorClasses = {
        gray: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300',
        blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        green: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
        yellow: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
        red: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
        orange: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    };

    const isPulsing = status === 'installing' || status === 'deploying';

    return (
        <Badge
            variant="outline"
            className={cn('border-transparent font-medium', colorClasses[statusColor as keyof typeof colorClasses] || colorClasses.gray)}
        >
            {isPulsing && <span className="mr-1.5 h-2 w-2 animate-pulse rounded-full bg-current" />}
            {statusLabel}
        </Badge>
    );
}
