import { Link } from '@inertiajs/react';
import { ArrowRightIcon, CheckIcon } from 'lucide-react';
import { type ReactNode } from 'react';

import BackdropGrid from '@/components/marketing/backdrop-grid';
import CallToAction from '@/components/marketing/call-to-action';
import FaqList, { type FaqItem } from '@/components/marketing/faq-list';
import {
    BackupVisual,
    CertificateVisual,
    MetricsVisual,
    ProcessVisual,
    TeamVisual,
    TerminalVisual,
} from '@/components/marketing/feature-visuals';
import ProductPreview from '@/components/marketing/product-preview';
import SectionHeading from '@/components/marketing/section-heading';
import { Button } from '@/components/ui/button';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
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
    className?: string;
}[] = [
    {
        title: 'Domains and SSL, handled',
        description:
            "Add a domain and flitops verifies DNS, issues a Let's Encrypt certificate, and renews it long before it expires. Wildcards included.",
        visual: <CertificateVisual />,
        className: 'sm:col-span-2',
    },
    {
        title: 'Backups you can restore',
        description:
            'Scheduled database backups to S3 or Cloudflare R2, restorable in a click.',
        visual: <BackupVisual />,
    },
    {
        title: 'A terminal in the browser',
        description:
            'A real shell on any server, behind a fresh password check.',
        visual: <TerminalVisual />,
    },
    {
        title: 'Workers that stay up',
        description:
            'Queue workers, daemons, and cron jobs, supervised and restarted on failure.',
        visual: <ProcessVisual />,
    },
    {
        title: 'Know before your users do',
        description:
            'Live server metrics, uptime checks on every site, and alerts when a certificate needs attention.',
        visual: <MetricsVisual />,
    },
    {
        title: 'Built for teams',
        description:
            'Invite your team with owner, admin, developer, and viewer roles. Everyone sees the same fleet, with the right level of access.',
        visual: <TeamVisual />,
        className:
            'sm:col-span-2 lg:col-span-3 lg:flex-row lg:items-center lg:gap-16 [&>div]:lg:flex-1',
    },
];

const steps = [
    {
        title: 'Connect your cloud',
        description:
            'Add an API token for DigitalOcean, Hetzner, Vultr, or AWS — or bring any Ubuntu server over SSH.',
        detail: 'Hetzner · CX32 · Falkenstein',
    },
    {
        title: 'Provision a server',
        description:
            'Pick a role and flitops installs Nginx, PHP, your database, Redis, and Node, hardened and ready.',
        detail: 'Nginx · PHP 8.4 · MySQL 8 · Redis',
    },
    {
        title: 'Push to deploy',
        description:
            'Link a repository. Every push builds a fresh release and switches over with zero downtime.',
        detail: 'git push origin main',
    },
];

const serverRoles = [
    {
        name: 'App server',
        description: 'Everything on one box: PHP, Nginx, database, and Redis.',
    },
    {
        name: 'Web server',
        description: 'PHP and Nginx, talking to a database elsewhere.',
    },
    {
        name: 'Worker server',
        description: 'Background jobs and queues, nothing public.',
    },
    {
        name: 'Database server',
        description: 'MySQL, PostgreSQL, or MariaDB on its own machine.',
    },
    {
        name: 'Cache server',
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
    className,
}: (typeof capabilities)[number]) {
    return (
        <div
            className={cn(
                'group flex flex-col justify-between gap-8 rounded-2xl border border-border bg-muted/30 p-6 transition-colors hover:bg-muted/50 sm:p-7',
                className,
            )}
        >
            <div>
                <h3 className="font-semibold tracking-tight">{title}</h3>
                <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                    {description}
                </p>
            </div>
            <div>{visual}</div>
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
            <section className="relative px-6 pt-16 pb-20 sm:pt-24">
                <BackdropGrid />

                <div className="mx-auto max-w-3xl text-center">
                    <Link
                        href={features()}
                        className="inline-flex items-center gap-2 rounded-full border border-border bg-background/70 py-1 pr-3 pl-1 text-xs font-medium text-muted-foreground shadow-xs backdrop-blur transition-colors hover:text-foreground"
                    >
                        <span className="rounded-full bg-brand px-2 py-0.5 text-white">
                            New
                        </span>
                        Provision servers on AWS
                        <ArrowRightIcon className="size-3" />
                    </Link>

                    <h1 className="mt-8 text-[2.75rem] leading-[1.02] font-semibold tracking-[-0.035em] text-balance sm:text-7xl">
                        Ship to servers you own.
                        <span className="block text-muted-foreground">
                            Skip the ops team.
                        </span>
                    </h1>

                    <p className="mx-auto mt-7 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground">
                        flitops provisions servers on your own cloud, installs a
                        production-ready stack, and turns every git push into a
                        zero-downtime deployment.
                    </p>

                    <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button
                            asChild
                            size="lg"
                            className="h-11 rounded-full px-6"
                        >
                            <Link href={canRegister ? register() : login()}>
                                Start for free
                                <ArrowRightIcon />
                            </Link>
                        </Button>
                        <Button
                            asChild
                            size="lg"
                            variant="outline"
                            className="h-11 rounded-full px-6"
                        >
                            <a href="#how-it-works">See how it works</a>
                        </Button>
                    </div>

                    <p className="mt-5 text-xs text-muted-foreground">
                        Free for your first server. No card required.
                    </p>
                </div>

                <div className="mt-16 sm:mt-20">
                    <ProductPreview />
                </div>
            </section>

            <section className="px-6 pb-8">
                <div className="mx-auto max-w-5xl">
                    <p className="text-center text-sm text-muted-foreground">
                        Works with the clouds and tools you already use
                    </p>
                    <ul className="mt-6 flex flex-wrap items-center justify-center gap-x-10 gap-y-4">
                        {integrations.map((integration) => (
                            <li
                                key={integration}
                                className="text-lg font-semibold tracking-tight text-foreground/35 transition-colors hover:text-foreground/70"
                            >
                                {integration}
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section className="px-6 py-24 sm:py-32">
                <div className="mx-auto max-w-6xl">
                    <SectionHeading
                        eyebrow="The platform"
                        title="Everything between git push and production"
                        description="The work a platform team would do for you, done on servers you control."
                    />

                    <div className="mt-16 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {capabilities.map((capability) => (
                            <Capability
                                key={capability.title}
                                {...capability}
                            />
                        ))}
                    </div>

                    <div className="mt-10 text-center">
                        <Link
                            href={features()}
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-foreground transition-colors hover:text-brand"
                        >
                            Explore every feature
                            <ArrowRightIcon className="size-4" />
                        </Link>
                    </div>
                </div>
            </section>

            <section
                id="how-it-works"
                className="scroll-mt-20 border-y border-border bg-muted/30 px-6 py-24 sm:py-32"
            >
                <div className="mx-auto max-w-6xl">
                    <SectionHeading
                        eyebrow="How it works"
                        title="From a blank account to live in minutes"
                        description="No runbooks, no hand-written Nginx configs, no SSH sessions at midnight."
                    />

                    <ol className="mt-16 grid gap-4 lg:grid-cols-3">
                        {steps.map((step, index) => (
                            <li
                                key={step.title}
                                className="relative rounded-2xl border border-border bg-background p-7"
                            >
                                <span className="font-log text-xs text-muted-foreground">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <h3 className="mt-6 text-lg font-semibold tracking-tight">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {step.description}
                                </p>
                                <p className="mt-8 rounded-lg bg-muted/60 px-3 py-2 font-log text-xs text-muted-foreground">
                                    {step.detail}
                                </p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            <section className="px-6 py-24 sm:py-32">
                <div className="mx-auto grid max-w-6xl items-center gap-16 lg:grid-cols-2">
                    <div>
                        <SectionHeading
                            align="left"
                            eyebrow="Servers"
                            title="One server today. A fleet when you need it."
                            description="Start with everything on a single machine. When traffic grows, split the database, queues, and cache onto their own servers and connect them privately, without rebuilding anything."
                        />
                        <div className="mt-10">
                            <p className="text-sm font-medium">Deploy any of</p>
                            <ul className="mt-3 flex flex-wrap gap-2">
                                {frameworks.map((framework) => (
                                    <li
                                        key={framework}
                                        className="rounded-full border border-border px-3 py-1 text-sm text-muted-foreground"
                                    >
                                        {framework}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border">
                        {serverRoles.map((role, index) => (
                            <li
                                key={role.name}
                                className="flex items-start gap-5 bg-background p-5 sm:p-6"
                            >
                                <span className="mt-0.5 font-log text-xs text-muted-foreground">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <div>
                                    <p className="font-medium">{role.name}</p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {role.description}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section className="px-6">
                <div className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl border border-border bg-muted/30 p-8 sm:p-14">
                    <div className="grid gap-12 lg:grid-cols-[1fr_1.1fr]">
                        <div>
                            <SectionHeading
                                align="left"
                                eyebrow="Security"
                                title="Secure by default, not by checklist"
                                description="Every server flitops builds starts hardened. The careful choices are already made, so you don't have to remember them."
                            />
                            <Button
                                asChild
                                variant="outline"
                                className="mt-8 rounded-full"
                            >
                                <Link href={security()}>
                                    How we protect your fleet
                                    <ArrowRightIcon />
                                </Link>
                            </Button>
                        </div>
                        <ul className="grid content-center gap-4 sm:grid-cols-2">
                            {securityPoints.map((point) => (
                                <li
                                    key={point}
                                    className="flex gap-3 text-sm leading-relaxed"
                                >
                                    <CheckIcon className="mt-0.5 size-4 shrink-0 text-brand dark:text-blue-400" />
                                    {point}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </section>

            <section className="px-6 py-24 sm:py-32">
                <div className="mx-auto grid max-w-6xl gap-12 lg:grid-cols-[1fr_1.6fr]">
                    <SectionHeading
                        align="left"
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
