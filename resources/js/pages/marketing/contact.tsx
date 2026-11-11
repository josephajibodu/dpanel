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

            <section className="px-6 pb-24 sm:pb-32">
                <div className="mx-auto grid max-w-4xl gap-4 sm:grid-cols-2">
                    {channels.map((channel) => (
                        <a
                            key={channel.title}
                            href={`mailto:${contactEmail}?subject=${encodeURIComponent(channel.subject)}`}
                            className="group flex flex-col rounded-2xl border border-border bg-background p-7 transition-all hover:-translate-y-0.5 hover:border-foreground/20 hover:shadow-lg hover:shadow-black/5"
                        >
                            <div className="flex items-center justify-between">
                                <h2 className="font-semibold tracking-tight">
                                    {channel.title}
                                </h2>
                                <ArrowUpRightIcon className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-foreground" />
                            </div>
                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                {channel.description}
                            </p>
                            <p className="mt-8 text-sm font-medium">
                                {contactEmail}
                            </p>
                        </a>
                    ))}
                </div>
            </section>
        </MarketingLayout>
    );
}
