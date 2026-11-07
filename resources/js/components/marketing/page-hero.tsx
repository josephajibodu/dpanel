import { type ReactNode } from 'react';

import BackdropGrid from '@/components/marketing/backdrop-grid';
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
        <section className="relative px-6 pt-20 pb-16 sm:pt-28 sm:pb-20">
            <BackdropGrid />
            <SectionHeading
                as="h1"
                eyebrow={eyebrow}
                title={title}
                description={description}
            />
            {children}
        </section>
    );
}
