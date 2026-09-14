import { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: React.ReactNode;
    className?: string;
    /** Frames the state in a dashed border, for an empty section inside a card
     *  rather than a whole empty page. */
    bordered?: boolean;
}

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
    bordered = false,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center py-12 text-center',
                bordered && 'rounded-lg border border-dashed py-10',
                className,
            )}
        >
            {Icon && (
                <div className="mb-4 rounded-full bg-muted p-3">
                    <Icon className="h-6 w-6 text-muted-foreground" />
                </div>
            )}
            <h3 className="text-sm font-medium text-foreground">{title}</h3>
            {description && (
                <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
