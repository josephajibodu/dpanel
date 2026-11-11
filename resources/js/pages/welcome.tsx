import { Link } from '@inertiajs/react';
import {
    ArrowRightIcon,
    CheckIcon,
    HardDrivesIcon,
} from '@phosphor-icons/react';
import { type ReactNode } from 'react';

import CallToAction from '@/components/marketing/call-to-action';
import FaqList, { type FaqItem } from '@/components/marketing/faq-list';
import {
    BackupVisual,
    CertificateVisual,
    MetricsVisual,
    ProcessVisual,
    ProviderVisual,
    ProvisionVisual,
    PushVisual,
    TeamVisual,
    TerminalVisual,
} from '@/components/marketing/feature-visuals';
import ProductPreview from '@/components/marketing/product-preview';
import Scene, { type SceneTone } from '@/components/marketing/scene';
import SectionHeading from '@/components/marketing/section-heading';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import MarketingLayout from '@/layouts/marketing-layout';
import {
    contact,
    features,
    login,
    pricing,
    register,
    security,
} from '@/routes';

const integrations = [
    'DigitalOcean',
    'Hetzner',
    'Vultr',
    'AWS',
    'GitHub',
    'GitLab',
    'Bitbucket',
    'Cloudflare R2',
];

const capabilities: {
    title: string;
    description: string;
    visual: ReactNode;
    tone: SceneTone;
}[] = [
    {
        title: 'Domains and SSL, handled',
        description:
            "Add a domain and flitops verifies DNS, issues a Let's Encrypt certificate, and renews it long before it expires.",
        visual: <CertificateVisual />,
        tone: 'green',
    },
    {
        title: 'Backups you can restore',
        description:
            'Scheduled database backups to S3 or Cloudflare R2, restorable in a click.',
        visual: <BackupVisual />,
        tone: 'blue',
    },
    {
        title: 'A terminal in the browser',
        description:
            'A real shell on any server, behind a fresh password check.',
        visual: <TerminalVisual />,
        tone: 'amber',
    },
    {
        title: 'Workers that stay up',
        description:
            'Queue workers, daemons, and cron jobs, supervised and restarted on failure.',
        visual: <ProcessVisual />,
        tone: 'violet',
    },
    {
        title: 'Know before your users do',
        description:
            'Live server metrics, uptime checks on every site, and alerts when a certificate needs attention.',
        visual: <MetricsVisual />,
        tone: 'green',
    },
    {
        title: 'Built for teams',
        description:
            'Owner, admin, developer, and viewer roles, so everyone sees the same fleet with the right level of access.',
        visual: <TeamVisual />,
        tone: 'blue',
    },
];

const steps: { title: string; description: string; visual: ReactNode }[] = [
    {
        title: 'Connect your cloud',
        description:
            'Add an API token for DigitalOcean, Hetzner, Vultr, or AWS, or bring any Ubuntu server over SSH.',
        visual: <ProviderVisual />,
    },
    {
        title: 'Provision a server',
        description:
            'Pick a role and flitops installs Nginx, PHP, your database, Redis, and Node, hardened and ready.',
        visual: <ProvisionVisual />,
    },
    {
        title: 'Push to deploy',
        description:
            'Link a repository. Every push builds a fresh release and switches over with zero downtime.',
        visual: <PushVisual />,
    },
];

const stats = [
    { value: '4', label: 'cloud providers, or any Ubuntu server over SSH' },
    { value: '5', label: 'server roles, from one box to a full fleet' },
    { value: '0', label: 'seconds of downtime when a deploy goes live' },
    { value: '$0', label: 'for your first server, with no card required' },
];

const serverRoles = [
    {
        name: 'App server',
        host: 'fra-app-1',
        description: 'Everything on one box: PHP, Nginx, database, and Redis.',
    },
    {
        name: 'Web server',
        host: 'fra-web-1',
        description: 'PHP and Nginx, talking to a database elsewhere.',
    },
    {
        name: 'Worker server',
        host: 'fra-worker-1',
        description: 'Background jobs and queues, nothing public.',
    },
    {
        name: 'Database server',
        host: 'fra-db-1',
        description: 'MySQL, PostgreSQL, or MariaDB on its own machine.',
    },
    {
        name: 'Cache server',
        host: 'fra-cache-1',
        description: 'A dedicated Redis for cache, sessions, and queues.',
    },
];

const frameworks = [
    'Laravel',
    'Symfony',
    'WordPress',
    'Any PHP app',
    'Static sites',
];

const securityPoints = [
    'Each site can run as its own isolated Linux user',
    'Credentials and environment files encrypted at rest',
    'Secrets kept off the command line and out of process lists',
    'Server host keys pinned and verified on every connection',
    'Two-factor authentication and role-based team access',
    'Revoke an SSH key from every server in one step',
];

const faqs: FaqItem[] = [
    {
        question: 'Do I need my own cloud account?',
        answer: 'Yes. flitops provisions servers inside your DigitalOcean, Hetzner, Vultr, or AWS account, or connects to any Ubuntu server you already run. You pay your provider directly, at their prices, and the servers stay yours.',
    },
    {
        question: 'What happens to my servers if I stop using flitops?',
        answer: 'They keep running. Everything flitops sets up is standard Nginx, PHP-FPM, Supervisor, and cron configuration on your own machine. There is nothing proprietary to remove and no lock-in.',
    },
    {
        question: 'Which applications can I deploy?',
        answer: 'Laravel, Symfony, WordPress, any other PHP application, and static sites. Each project type comes with a sensible deploy script you can edit, and Node is available for building front-end assets.',
    },
    {
        question: 'Does flitops have access to my application data?',
        answer: 'flitops connects to your servers over SSH to configure them and run deployments. Your application traffic and database never pass through us. Credentials we must hold are encrypted at rest.',
    },
];

function Capability({
    title,
    description,
    visual,
    tone,
}: (typeof capabilities)[number]) {
    return (
        <div className="flex flex-col overflow-hidden rounded-2xl border border-border bg-card">
            <Scene tone={tone} className="flex h-60 items-center px-6 sm:px-8">
                <div className="w-full">{visual}</div>
            </Scene>
            <div className="p-6 sm:p-8">
                <h3 className="text-xl font-medium tracking-tight">{title}</h3>
                <p className="mt-2 leading-relaxed text-muted-foreground">
                    {description}
                </p>
            </div>
        </div>
    );
}

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    return (
        <MarketingLayout
            title="Deploy to servers you own"
            description="flitops provisions servers on your own cloud, installs your stack, and ships every git push as a zero-downtime deployment."
            canRegister={canRegister}
        >
            <section className="px-6 pt-14 sm:pt-24 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    <h1 className="max-w-5xl text-[2.75rem] leading-[1.02] font-medium tracking-[-0.035em] text-balance sm:text-7xl lg:text-[5.25rem]">
                        The deployment platform for{' '}
                        <span className="text-brand dark:text-blue-400">
                            servers you own.
                        </span>
                    </h1>

                    <p className="mt-8 max-w-2xl text-lg leading-relaxed text-pretty text-muted-foreground sm:text-xl">
                        flitops provisions servers on your own cloud, installs a
                        production-ready stack, and turns every git push into a
                        zero-downtime deployment.
                    </p>

                    <div className="mt-10 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Button
                            asChild
                            size="lg"
                            className="h-12 px-7 text-base"
                        >
                            <Link href={canRegister ? register() : login()}>
                                Start for free
                            </Link>
                        </Button>
                        <Button
                            asChild
                            size="lg"
                            variant="outline"
                            className="h-12 px-7 text-base"
                        >
                            <a href="#how-it-works">See how it works</a>
                        </Button>
                        <p className="text-sm text-muted-foreground sm:ml-3">
                            Free for your first server. No card required.
                        </p>
                    </div>

                    <Scene
                        tone="brand"
                        className="mt-16 rounded-3xl px-4 pt-4 sm:mt-20 sm:px-10 sm:pt-10 lg:px-16 lg:pt-16"
                    >
                        <ProductPreview />
                    </Scene>
                </div>
            </section>

            <section className="px-6 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    <div className="flex flex-col gap-5 border-b border-border py-10 lg:flex-row lg:items-center lg:gap-16">
                        <p className="shrink-0 text-sm text-muted-foreground">
                            Works with
                        </p>
                        <ul className="flex flex-wrap gap-x-10 gap-y-3 font-medium lg:flex-1 lg:justify-between">
                            {integrations.map((integration) => (
                                <li key={integration}>{integration}</li>
                            ))}
                        </ul>
                    </div>

                    <dl className="grid grid-cols-2 border-b border-border lg:grid-cols-4">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className="border-border py-10 pr-6 not-first:pl-6 max-lg:odd:pl-0 max-lg:even:border-l max-lg:nth-[n+3]:border-t sm:py-12 lg:not-first:border-l"
                            >
                                <dd className="text-5xl font-medium tracking-[-0.035em] sm:text-6xl">
                                    {stat.value}
                                </dd>
                                <dt className="mt-3 max-w-[14rem] text-muted-foreground">
                                    {stat.label}
                                </dt>
                            </div>
                        ))}
                    </dl>
                </div>
            </section>

            <section className="px-6 py-24 sm:py-32 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    <div className="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                        <SectionHeading
                            eyebrow="The platform"
                            title="Everything between git push and production"
                            description="The work a platform team would do for you, done on servers you control."
                        />
                        <Link
                            href={features()}
                            className="inline-flex shrink-0 items-center gap-2 font-medium underline-offset-4 hover:underline"
                        >
                            Explore every feature
                            <ArrowRightIcon className="size-4" />
                        </Link>
                    </div>

                    <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {capabilities.map((capability) => (
                            <Capability
                                key={capability.title}
                                {...capability}
                            />
                        ))}
                    </div>
                </div>
            </section>

            <section
                id="how-it-works"
                className="scroll-mt-18 bg-muted px-6 py-24 sm:py-32 lg:px-8"
            >
                <div className="mx-auto max-w-7xl">
                    <SectionHeading
                        eyebrow="How it works"
                        title="From a blank account to live in minutes"
                        description="No runbooks, no hand-written Nginx configs, no SSH sessions at midnight."
                    />

                    <ol className="mt-16 grid gap-5 lg:grid-cols-3">
                        {steps.map((step, index) => (
                            <li
                                key={step.title}
                                className="flex flex-col rounded-2xl bg-card p-6 shadow-card sm:p-8"
                            >
                                <span className="flex size-9 items-center justify-center rounded-full bg-brand font-log text-sm font-medium text-white">
                                    {index + 1}
                                </span>
                                <h3 className="mt-6 text-2xl font-medium tracking-tight">
                                    {step.title}
                                </h3>
                                <p className="mt-3 mb-8 leading-relaxed text-muted-foreground">
                                    {step.description}
                                </p>
                                <div className="mt-auto">{step.visual}</div>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            <section className="px-6 py-24 sm:py-32 lg:px-8">
                <div className="mx-auto grid max-w-7xl items-center gap-14 lg:grid-cols-2 lg:gap-20">
                    <div>
                        <SectionHeading
                            eyebrow="Servers"
                            title="One server today. A fleet when you need it."
                            description="Start with everything on a single machine. When traffic grows, split the database, queues, and cache onto their own servers and connect them privately, without rebuilding anything."
                        />
                        <div className="mt-10">
                            <p className="text-sm text-muted-foreground">
                                Deploy any of
                            </p>
                            <ul className="mt-3 flex flex-wrap gap-2 font-medium">
                                {frameworks.map((framework) => (
                                    <li
                                        key={framework}
                                        className="rounded-full bg-secondary px-3.5 py-1.5 text-sm"
                                    >
                                        {framework}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <Scene tone="green" className="rounded-3xl p-4 sm:p-10">
                        <ul className="divide-y divide-border overflow-hidden rounded-2xl bg-card shadow-overlay">
                            {serverRoles.map((role) => (
                                <li
                                    key={role.name}
                                    className="flex items-center gap-4 px-5 py-4"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary">
                                        <HardDrivesIcon className="size-[18px]" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {role.name}
                                            <span className="ml-2 font-log text-xs font-normal text-muted-foreground">
                                                {role.host}
                                            </span>
                                        </p>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {role.description}
                                        </p>
                                    </div>
                                    <StatusBadge
                                        status="Active"
                                        color="green"
                                        className="ml-auto max-sm:hidden"
                                    />
                                </li>
                            ))}
                        </ul>
                    </Scene>
                </div>
            </section>

            <section className="dark bg-neutral-950 px-6 py-24 text-foreground sm:py-32 lg:px-8">
                <div className="mx-auto grid max-w-7xl gap-14 lg:grid-cols-2 lg:gap-20">
                    <div>
                        <SectionHeading
                            eyebrow="Security"
                            title="Secure by default, not by checklist"
                            description="Every server flitops builds starts hardened. The careful choices are already made, so you don't have to remember them."
                        />
                        <Button
                            asChild
                            size="lg"
                            className="mt-10 h-12 px-7 text-base"
                        >
                            <Link href={security()}>
                                How we protect your fleet
                            </Link>
                        </Button>
                    </div>
                    <ul className="self-start border-t border-border">
                        {securityPoints.map((point) => (
                            <li
                                key={point}
                                className="flex gap-4 border-b border-border py-5"
                            >
                                <CheckIcon
                                    weight="bold"
                                    className="mt-1 size-4 shrink-0 text-emerald-400"
                                />
                                {point}
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section className="px-6 pt-24 sm:pt-32 lg:px-8">
                <div className="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[1fr_1.4fr] lg:gap-20">
                    <SectionHeading
                        eyebrow="Questions"
                        title="Good to know"
                        description={
                            <>
                                Something else on your mind?{' '}
                                <Link
                                    href={pricing()}
                                    className="text-foreground underline underline-offset-4"
                                >
                                    See pricing
                                </Link>{' '}
                                or{' '}
                                <Link
                                    href={contact()}
                                    className="text-foreground underline underline-offset-4"
                                >
                                    get in touch
                                </Link>
                                .
                            </>
                        }
                    />
                    <FaqList items={faqs} />
                </div>
            </section>

            <CallToAction />
        </MarketingLayout>
    );
}
