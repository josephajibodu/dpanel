import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * The eyebrow, title, and lead paragraph that open a section of a public page.
 */
export default function SectionHeading({
    eyebrow,
    title,
    description,
    align = 'center',
    as: Heading = 'h2',
    className,
}: {
    eyebrow?: string;
    title: ReactNode;
    description?: ReactNode;
    align?: 'center' | 'left';
    as?: 'h1' | 'h2';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'max-w-2xl',
                align === 'center' && 'mx-auto text-center',
                className,
            )}
        >
            {eyebrow && (
                <p className="text-sm font-medium text-brand dark:text-blue-400">
                    {eyebrow}
                </p>
            )}
            <Heading
                className={cn(
                    'font-semibold tracking-[-0.025em] text-balance',
                    Heading === 'h1'
                        ? 'text-4xl leading-[1.05] sm:text-6xl'
                        : 'text-3xl leading-tight sm:text-[2.75rem]',
                    eyebrow && 'mt-3',
                )}
            >
                {title}
            </Heading>
            {description && (
                <p
                    className={cn(
                        'mt-5 text-lg leading-relaxed text-pretty text-muted-foreground',
                        align === 'center' && 'mx-auto max-w-xl',
                    )}
                >
                    {description}
                </p>
            )}
        </div>
    );
}
