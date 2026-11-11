import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface StatGroupProps {
    children: ReactNode;
    className?: string;
}

/** Groups a card, or a strip of `Stat` cells, as one block on the page. */
export function StatGroup({ children, className }: StatGroupProps) {
    return (
        <div
            className={cn(
                'rounded-2xl',
                className,
            )}
        >
            {children}
        </div>
    );
}
