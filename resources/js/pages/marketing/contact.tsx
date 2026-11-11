import { ArrowUpRightIcon } from '@phosphor-icons/react';

import PageHero from '@/components/marketing/page-hero';
import MarketingLayout from '@/layouts/marketing-layout';

const contactEmail = 'hello@flitops.com';

const channels = [
    {
        title: 'Sales',
        description:
            'Larger fleets, invoicing, contracts, or a walkthrough for your team.',
        subject: 'Sales enquiry',
    },
    {
        title: 'Support',
        description:
            'Stuck on a server or a deploy? Include your team name and the site involved.',
        subject: 'Support request',
    },
    {
        title: 'Security',
        description:
            'Report a vulnerability privately. We acknowledge reports within two business days.',
        subject: 'Security report',
    },
    {
        title: 'Everything else',
        description: 'Partnerships, press, feedback, or just to say hello.',
        subject: 'Hello',
    },
];

export default function Contact() {
    return (
        <MarketingLayout
            title="Contact"
            description="Get in touch with the flitops team."
        >
            <PageHero
                eyebrow="Contact"
                title="We’d love to hear from you"
                description="Real people read every message. We usually reply within one business day."
            />

            <section className="px-6 pb-24 sm:pb-32 lg:px-8">
                <ul className="mx-auto max-w-7xl border-t border-border">
                    {channels.map((channel) => (
                        <li
                            key={channel.title}
                            className="border-b border-border"
                        >
                            <a
                                href={`mailto:${contactEmail}?subject=${encodeURIComponent(channel.subject)}`}
                                className="group grid gap-x-10 gap-y-2 py-8 lg:grid-cols-[1fr_1.4fr_auto] lg:items-center"
                            >
                                <h2 className="text-2xl font-medium tracking-tight">
                                    {channel.title}
                                </h2>
                                <p className="max-w-md leading-relaxed text-muted-foreground">
                                    {channel.description}
                                </p>
                                <p className="mt-2 flex items-center gap-2 font-medium underline-offset-4 group-hover:underline lg:mt-0">
                                    {contactEmail}
                                    <ArrowUpRightIcon className="size-4" />
                                </p>
                            </a>
                        </li>
                    ))}
                </ul>
            </section>
        </MarketingLayout>
    );
}
