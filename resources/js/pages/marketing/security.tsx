import { Link } from '@inertiajs/react';

import CallToAction from '@/components/marketing/call-to-action';
import PageHero from '@/components/marketing/page-hero';
import SectionHeading from '@/components/marketing/section-heading';
import MarketingLayout from '@/layouts/marketing-layout';
import { contact, privacy } from '@/routes';

const principles = [
    {
        title: 'Your servers stay yours',
        description:
            'Servers live in your own cloud account. Application traffic and database contents go straight to them and never pass through flitops.',
    },
    {
        title: 'Least access by default',
        description:
            'flitops holds only what it needs to configure your servers and deploy your code, and every team member gets only the access their role allows.',
    },
    {
        title: 'Nothing proprietary on the box',
        description:
            'We set up standard Nginx, PHP-FPM, Supervisor, and cron. You can inspect every file we write, and everything keeps working if you leave.',
    },
];

const practices = [
    {
        area: 'On your servers',
        items: [
            {
                title: 'Site isolation',
                description:
                    'Each site can run as its own Linux user, so one compromised application cannot read another’s files.',
            },
            {
                title: 'Pinned host keys',
                description:
                    'We record each server’s SSH host key the first time we connect and refuse to talk to a server that presents a different one.',
            },
            {
                title: 'Secrets off the command line',
                description:
                    'Database passwords and storage keys are passed through protected files, never as arguments visible in the process list.',
            },
            {
                title: 'Firewalled internal services',
                description:
                    'Databases and Redis are opened only to the specific servers you connect to them, never to the internet.',
            },
            {
                title: 'Safe imports',
                description:
                    'Imported database dumps are checked so they cannot run shell commands on your server.',
            },
        ],
    },
    {
        area: 'In flitops',
        items: [
            {
                title: 'Encryption at rest',
                description:
                    'Cloud tokens, Git credentials, storage keys, database passwords, and environment files are encrypted before they are stored.',
            },
            {
                title: 'Hidden by default',
                description:
                    'Secrets are never sent to your browser unless you ask to reveal them, and revealing one is an explicit action.',
            },
            {
                title: 'Re-confirmed terminal access',
                description:
                    'Opening a browser terminal requires a fresh password confirmation and the right team permission.',
            },
            {
                title: 'Private deploy webhooks',
                description:
                    'Each site’s deploy webhook is addressed by its own long, random secret, so no one can trigger a deploy by guessing a URL.',
            },
        ],
    },
    {
        area: 'For your team',
        items: [
            {
                title: 'Two-factor authentication',
                description:
                    'Protect accounts with an authenticator app and one-time recovery codes.',
            },
            {
                title: 'Role-based permissions',
                description:
                    'Owners, admins, developers, and viewers each see and do only what their role allows, checked on the server for every action.',
            },
            {
                title: 'Fleet-wide key revocation',
                description:
                    'When someone leaves, revoke their SSH key from every server it was synced to in a single step.',
            },
        ],
    },
];

export default function Security() {
    return (
        <MarketingLayout
            title="Security"
            description="How flitops protects your servers, credentials, and team."
        >
            <PageHero
                eyebrow="Security"
                title="Built to be trusted with your infrastructure"
                description="flitops has the keys to your servers. Here is exactly how we take care of them."
            />

            <section className="px-6">
                <div className="mx-auto grid max-w-6xl gap-4 md:grid-cols-3">
                    {principles.map((principle) => (
                        <div
                            key={principle.title}
                            className="rounded-2xl border border-border bg-muted/30 p-7"
                        >
                            <h2 className="font-semibold tracking-tight">
                                {principle.title}
                            </h2>
                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                {principle.description}
                            </p>
                        </div>
                    ))}
                </div>
            </section>

            <div className="px-6 pt-24 sm:pt-32">
                <div className="mx-auto max-w-6xl">
                    <SectionHeading
                        eyebrow="In practice"
                        title="The details that matter"
                        description="Security is mostly a long list of careful defaults. These are ours."
                    />

                    <div className="mt-16 divide-y divide-border border-y border-border">
                        {practices.map((practice) => (
                            <section
                                key={practice.area}
                                className="grid gap-10 py-14 lg:grid-cols-[1fr_2.2fr]"
                            >
                                <h3 className="text-xl font-semibold tracking-tight">
                                    {practice.area}
                                </h3>
                                <dl className="grid gap-x-10 gap-y-9 sm:grid-cols-2">
                                    {practice.items.map((item) => (
                                        <div
                                            key={item.title}
                                            className="border-l border-border pl-5"
                                        >
                                            <dt className="font-medium">
                                                {item.title}
                                            </dt>
                                            <dd className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                                {item.description}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        ))}
                    </div>
                </div>
            </div>

            <section className="px-6 pt-24 sm:pt-32">
                <div className="mx-auto grid max-w-6xl gap-8 rounded-3xl border border-border p-8 sm:p-12 lg:grid-cols-2 lg:items-center">
                    <div>
                        <h2 className="text-2xl font-semibold tracking-tight">
                            Found a vulnerability?
                        </h2>
                        <p className="mt-3 max-w-md leading-relaxed text-muted-foreground">
                            We appreciate responsible disclosure. Report it
                            privately and we will acknowledge it within two
                            business days, keep you updated, and credit you once
                            it is fixed if you would like.
                        </p>
                    </div>
                    <div className="flex flex-col gap-3 text-sm lg:items-end">
                        <a
                            href="mailto:hello@flitops.com?subject=Security%20report"
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            hello@flitops.com
                        </a>
                        <p className="text-muted-foreground">
                            Questions about data? Read our{' '}
                            <Link
                                href={privacy()}
                                className="text-foreground underline underline-offset-4"
                            >
                                privacy policy
                            </Link>{' '}
                            or{' '}
                            <Link
                                href={contact()}
                                className="text-foreground underline underline-offset-4"
                            >
                                contact us
                            </Link>
                            .
                        </p>
                    </div>
                </div>
            </section>

            <CallToAction />
        </MarketingLayout>
    );
}
