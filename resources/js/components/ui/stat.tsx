import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface StatProps {
    label: string;
    value: ReactNode;
    hint?: string;
    tone?: 'default' | 'warning';
    action?: ReactNode;
    /** A cell of a stat strip: the surrounding grid draws the card and the dividers. */
    bordered?: boolean;
    className?: string;
    /** Override the value's default `text-2xl font-semibold` — e.g. for a longer text value
     *  (a name, a date) that shouldn't compete with a short glanceable number. */
    valueClassName?: string;
}

export function Stat({
    label,
    value,
    hint,
    tone = 'default',
    action,
    bordered = false,
    className,
    valueClassName,
}: StatProps) {
    return (
        <div
            className={cn(
                bordered
                    ? 'bg-card px-5 py-4'
                    : 'px-6 py-4',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium text-muted-foreground">{label}</p>
                {action}
            </div>
            <p
                className={cn(
                    'mt-1 text-xl font-semibold tracking-tight',
                    tone === 'warning' && 'text-destructive',
                    valueClassName,
                )}
            >
                {value}
            </p>
            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}
