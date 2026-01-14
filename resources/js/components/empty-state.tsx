import { cn } from '@/lib/utils';
import { LucideIcon } from 'lucide-react';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: React.ReactNode;
    className?: string;
}

export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center justify-center py-12 text-center', className)}>
            {Icon && (
                <div className="bg-muted mb-4 rounded-full p-3">
                    <Icon className="text-muted-foreground h-6 w-6" />
                </div>
            )}
            <h3 className="text-foreground text-sm font-medium">{title}</h3>
            {description && <p className="text-muted-foreground mt-1 max-w-sm text-sm">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
