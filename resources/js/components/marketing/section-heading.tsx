import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * The label, title, and lead paragraph that open a section of a public page.
 */
export default function SectionHeading({
    eyebrow,
    title,
    description,
    as: Heading = 'h2',
    className,
}: {
    eyebrow?: string;
    title: ReactNode;
    description?: ReactNode;
    as?: 'h1' | 'h2';
    className?: string;
}) {
    return (
        <div className={cn('max-w-3xl', className)}>
            {eyebrow && (
                <p className="font-log text-xs font-medium tracking-[0.08em] text-brand uppercase dark:text-blue-400">
                    {eyebrow}
                </p>
            )}
            <Heading
                className={cn(
                    'font-medium text-balance',
                    Heading === 'h1'
                        ? 'text-[2.75rem] leading-[1.02] tracking-[-0.035em] sm:text-7xl'
                        : 'text-4xl leading-[1.05] tracking-[-0.03em] sm:text-5xl',
                    eyebrow && 'mt-5',
                )}
            >
                {title}
            </Heading>
            {description && (
                <p
                    className={cn(
                        'max-w-2xl leading-relaxed text-pretty text-muted-foreground',
                        Heading === 'h1'
                            ? 'mt-7 text-lg sm:text-xl'
                            : 'mt-5 text-lg',
                    )}
                >
                    {description}
                </p>
            )}
        </div>
    );
}
