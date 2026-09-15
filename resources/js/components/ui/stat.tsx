import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface StatProps {
    label: string;
    value: ReactNode;
    hint?: string;
    tone?: 'default' | 'warning';
    action?: ReactNode;
    /** Wraps the cell in its own bordered, shadowed box, for a grid of standalone tiles. */
    bordered?: boolean;
    className?: string;
}

export function Stat({
    label,
    value,
    hint,
    tone = 'default',
    action,
    bordered = false,
    className,
}: StatProps) {
    return (
        <div
            className={cn(
                bordered
                    ? 'rounded-lg border bg-card p-4 shadow-md shadow-black/5 dark:shadow-black/20'
                    : 'px-6 py-4',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">{label}</p>
                {action}
            </div>
            <p
                className={cn(
                    'text-2xl font-semibold',
                    tone === 'warning' && 'text-destructive',
                )}
            >
                {value}
            </p>
            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}
