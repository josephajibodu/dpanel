import { Link } from '@inertiajs/react';
import { CheckIcon, MinusIcon } from '@phosphor-icons/react';
import { useState } from 'react';

import CallToAction from '@/components/marketing/call-to-action';
import FaqList, { type FaqItem } from '@/components/marketing/faq-list';
import PageHero from '@/components/marketing/page-hero';
import SectionHeading from '@/components/marketing/section-heading';
import { Button } from '@/components/ui/button';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { contact, register } from '@/routes';

type BillingPeriod = 'monthly' | 'yearly';

type Currency = 'usd' | 'ngn';

/** Monthly price in each currency, with the yearly figure as a per-month rate. */
type PlanPrice = { monthly: number; yearly: number };

type Plan = {
    name: string;
    description: string;
    prices: Record<Currency, PlanPrice>;
    cta: string;
    isFeatured?: boolean;
    highlights: string[];
};

const plans: Plan[] = [
    {
        name: 'Hobby',
        description: 'For side projects and trying flitops on a real server.',
        prices: {
            usd: { monthly: 0, yearly: 0 },
            ngn: { monthly: 0, yearly: 0 },
        },
        cta: 'Start for free',
        highlights: [
            '1 server',
            'Unlimited sites and deployments',
            "Let's Encrypt SSL",
            'Browser terminal',
            'Community support',
        ],
    },
    {
        name: 'Pro',
        description: 'For developers and freelancers running production apps.',
        prices: {
            usd: { monthly: 5, yearly: 4 },
            ngn: { monthly: 5000, yearly: 4000 },
        },
        cta: 'Start 14-day trial',
        isFeatured: true,
        highlights: [
            'Unlimited servers',
            'Scheduled database backups',
            'Queue workers and cron jobs',
            'Wildcard certificates',
            'Uptime monitoring',
            'Email support',
        ],
    },
    {
        name: 'Team',
        description: 'For agencies and teams sharing a fleet.',
        prices: {
            usd: { monthly: 15, yearly: 12 },
            ngn: { monthly: 15000, yearly: 12000 },
        },
        cta: 'Start 14-day trial',
        highlights: [
            'Everything in Pro',
            'Unlimited team members',
            'Owner, admin, developer, and viewer roles',
            'Multiple teams for clients',
            'Priority support',
        ],
    },
];

const comparisonRows: {
    label: string;
    values: [string | boolean, string | boolean, string | boolean];
}[] = [
    { label: 'Servers', values: ['1', 'Unlimited', 'Unlimited'] },
    {
        label: 'Sites and deployments',
        values: ['Unlimited', 'Unlimited', 'Unlimited'],
    },
    { label: 'Team members', values: ['1', '1', 'Unlimited'] },
    { label: 'Zero-downtime deploys and rollback', values: [true, true, true] },
    { label: "Let's Encrypt SSL", values: [true, true, true] },
    { label: 'Wildcard certificates', values: [false, true, true] },
    { label: 'Database backups to S3 or R2', values: [false, true, true] },
    { label: 'Queue workers and cron jobs', values: [true, true, true] },
    { label: 'Uptime and certificate monitoring', values: [false, true, true] },
    { label: 'Browser terminal', values: [true, true, true] },
    { label: 'Role-based access', values: [false, false, true] },
    { label: 'Support', values: ['Community', 'Email', 'Priority'] },
];

const faqs: FaqItem[] = [
    {
        question: 'Is the price per server?',
        answer: 'No. Paid plans include unlimited servers for one flat price. Your cloud provider bills you separately for the servers themselves, at their own rates.',
    },
    {
        question: 'Do I need a card to start?',
        answer: 'No. The Hobby plan is free with no card, and the trials on Pro and Team do not ask for payment details until you decide to continue.',
    },
    {
        question: 'Can I change plans later?',
        answer: 'Any time. Upgrades take effect immediately and downgrades apply at the end of your billing period. Your servers keep running either way.',
    },
    {
        question: 'What happens if I cancel?',
        answer: 'Your servers and sites keep running on your cloud account exactly as they are. You only lose access to the flitops dashboard and automation.',
    },
];

const currencyLabels: Record<Currency, string> = {
    usd: 'USD $',
    ngn: 'NGN ₦',
};

function formatPrice(amount: number, currency: Currency): string {
    return new Intl.NumberFormat(currency === 'ngn' ? 'en-NG' : 'en-US', {
        style: 'currency',
        currency: currency.toUpperCase(),
        maximumFractionDigits: 0,
    }).format(amount);
}

function PlanValue({ value }: { value: string | boolean }) {
    if (value === true) {
        return (
            <CheckIcon
                className="size-4 text-foreground"
                aria-label="Included"
            />
        );
    }

    if (value === false) {
        return (
            <MinusIcon
                className="size-4 text-muted-foreground/50"
                aria-label="Not included"
            />
        );
    }

    return <span>{value}</span>;
}

export default function Pricing() {
    const [billingPeriod, setBillingPeriod] = useState<BillingPeriod>('yearly');
    const [currency, setCurrency] = useState<Currency>('usd');

    return (
        <MarketingLayout
            title="Pricing"
            description="Simple, flat pricing for flitops. Unlimited servers on paid plans, and a free plan for your first server."
        >
            <PageHero
                eyebrow="Pricing"
                title="Flat pricing. Unlimited servers."
                description="One price for the platform, however large your fleet grows. You pay your cloud provider directly for the servers."
            >
                <div className="mt-12 flex flex-wrap gap-3">
                    <div
                        role="radiogroup"
                        aria-label="Billing period"
                        className="inline-flex rounded-full bg-secondary p-1 text-sm"
                    >
                        {(['monthly', 'yearly'] as const).map((period) => (
                            <button
                                key={period}
                                type="button"
                                role="radio"
                                aria-checked={billingPeriod === period}
                                onClick={() => setBillingPeriod(period)}
                                className={cn(
                                    'rounded-full px-4 py-1.5 font-medium capitalize transition-colors',
                                    billingPeriod === period
                                        ? 'bg-background text-foreground shadow-button'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {period}
                                {period === 'yearly' && (
                                    <span className="ml-1.5 text-muted-foreground">
                                        −20%
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                    <div
                        role="radiogroup"
                        aria-label="Currency"
                        className="inline-flex rounded-full bg-secondary p-1 text-sm"
                    >
                        {(['usd', 'ngn'] as const).map((option) => (
                            <button
                                key={option}
                                type="button"
                                role="radio"
                                aria-checked={currency === option}
                                onClick={() => setCurrency(option)}
                                className={cn(
                                    'rounded-full px-4 py-1.5 font-medium transition-colors',
                                    currency === option
                                        ? 'bg-background text-foreground shadow-button'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {currencyLabels[option]}
                            </button>
                        ))}
                    </div>
                </div>
            </PageHero>

            <section className="px-6 lg:px-8">
                <div className="mx-auto grid max-w-7xl overflow-hidden rounded-2xl border border-border lg:grid-cols-3">
                    {plans.map((plan) => {
                        const price = plan.prices[currency][billingPeriod];

                        return (
                            <div
                                key={plan.name}
                                className={cn(
                                    'flex flex-col border-border p-8 not-last:border-b sm:p-10 lg:not-last:border-r lg:not-last:border-b-0',
                                    plan.isFeatured && 'bg-muted',
                                )}
                            >
                                <div className="flex items-center justify-between">
                                    <h2 className="text-2xl font-medium tracking-tight">
                                        {plan.name}
                                    </h2>
                                    {plan.isFeatured && (
                                        <span className="rounded-full bg-foreground px-2.5 py-0.5 text-xs font-medium text-background">
                                            Most popular
                                        </span>
                                    )}
                                </div>
                                <p className="mt-2 min-h-12 text-muted-foreground">
                                    {plan.description}
                                </p>

                                <p className="mt-10 flex items-baseline gap-2">
                                    <span className="text-6xl font-normal tracking-[-0.035em] tabular-nums">
                                        {formatPrice(price, currency)}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {price === 0 ? 'forever' : '/ month'}
                                    </span>
                                </p>
                                <p className="mt-2 h-5 text-sm text-muted-foreground">
                                    {price > 0 &&
                                        billingPeriod === 'yearly' &&
                                        `Billed ${formatPrice(price * 12, currency)} yearly`}
                                </p>

                                <Button
                                    asChild
                                    size="lg"
                                    variant={
                                        plan.isFeatured ? 'default' : 'outline'
                                    }
                                    className="mt-8 h-12 text-base"
                                >
                                    <Link href={register()}>{plan.cta}</Link>
                                </Button>

                                <ul className="mt-10 space-y-3.5 border-t border-border pt-8">
                                    {plan.highlights.map((highlight) => (
                                        <li
                                            key={highlight}
                                            className="flex gap-3"
                                        >
                                            <CheckIcon className="mt-1 size-4 shrink-0" />
                                            {highlight}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    })}
                </div>

                <p className="mx-auto mt-8 max-w-7xl text-muted-foreground">
                    Running a large fleet or need an invoice and a contract?{' '}
                    <Link
                        href={contact()}
                        className="text-foreground underline underline-offset-4"
                    >
                        Talk to us
                    </Link>
                    .
                </p>
            </section>

            <section className="px-6 pt-24 sm:pt-32 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    <SectionHeading title="Compare plans" />
                    <div className="mt-12 overflow-x-auto">
                        <table className="w-full min-w-[640px]">
                            <thead>
                                <tr className="border-b border-foreground">
                                    <th
                                        scope="col"
                                        className="py-4 pr-4 text-left"
                                    >
                                        <span className="sr-only">Feature</span>
                                    </th>
                                    {plans.map((plan) => (
                                        <th
                                            key={plan.name}
                                            scope="col"
                                            className="w-1/5 px-4 py-4 text-left text-lg font-medium"
                                        >
                                            {plan.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border border-b border-border">
                                {comparisonRows.map((row) => (
                                    <tr key={row.label}>
                                        <th
                                            scope="row"
                                            className="py-4 pr-4 text-left font-normal"
                                        >
                                            {row.label}
                                        </th>
                                        {row.values.map((value, index) => (
                                            <td
                                                key={index}
                                                className="px-4 py-4 text-muted-foreground"
                                            >
                                                <PlanValue value={value} />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section className="px-6 pt-24 sm:pt-32 lg:px-8">
                <div className="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[1fr_1.4fr] lg:gap-20">
                    <SectionHeading title="Billing questions" />
                    <FaqList items={faqs} />
                </div>
            </section>

            <CallToAction />
        </MarketingLayout>
    );
}
