import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface StatGroupProps {
    children: ReactNode;
    className?: string;
}

/** A thin, unshadowed outer frame for a grid of bordered `Stat` tiles — the tiles carry
 *  their own shadow, so nesting them in here gives the layered card effect. */
export function StatGroup({ children, className }: StatGroupProps) {
    return (
        <div
            className={cn(
                'rounded-xl border border-border/70 bg-card/50 p-1.5',
                className,
            )}
        >
            {children}
        </div>
    );
}
