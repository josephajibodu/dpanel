import { CheckIcon } from '@phosphor-icons/react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
    title: string;
    description?: string;
    action?: React.ReactNode;
    /** Short lines on what the user gets once they add the first item. */
    points?: string[];
    /** A docs page that explains the feature, shown as "Read the docs". */
    docsHref?: string;
    className?: string;
}

export function EmptyState({
    title,
    description,
    action,
    points,
    docsHref,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center rounded-lg border border-dashed py-10 text-center',
                className,
            )}
        >
            <h3 className="text-sm font-medium text-foreground">{title}</h3>
            {description && (
                <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {points && points.length > 0 && (
                <ul className="mt-5 space-y-2 text-left">
                    {points.map((point) => (
                        <li
                            key={point}
                            className="flex items-start gap-2 text-sm text-muted-foreground"
                        >
                            <CheckIcon className="mt-0.5 size-4 shrink-0 text-foreground" />
                            {point}
                        </li>
                    ))}
                </ul>
            )}
            {(action || docsHref) && (
                <div
                    className={cn(
                        'flex flex-wrap items-center justify-center gap-2',
                        points && points.length > 0 ? 'mt-6' : 'mt-4',
                    )}
                >
                    {action}
                    {docsHref && (
                        <Button variant="ghost" asChild>
                            <a href={docsHref} target="_blank" rel="noreferrer">
                                Read the docs
                            </a>
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
