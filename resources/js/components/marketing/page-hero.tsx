import { type ReactNode } from 'react';

import SectionHeading from '@/components/marketing/section-heading';

/**
 * The opening band of an inner public page (pricing, security, about, …).
 */
export default function PageHero({
    eyebrow,
    title,
    description,
    children,
}: {
    eyebrow: string;
    title: ReactNode;
    description: ReactNode;
    children?: ReactNode;
}) {
    return (
        <section className="px-6 pt-16 pb-16 sm:pt-24 sm:pb-20 lg:px-8">
            <div className="mx-auto max-w-7xl">
                <SectionHeading
                    as="h1"
                    eyebrow={eyebrow}
                    title={title}
                    description={description}
                />
                {children}
            </div>
        </section>
    );
}
