import { Link } from '@inertiajs/react';
import { CheckIcon, MinusIcon } from '@phosphor-icons/react';
import { useState } from 'react';

import CallToAction from '@/components/marketing/call-to-action';
import FaqList, { type FaqItem } from '@/components/marketing/faq-list';
import PageHero from '@/components/marketing/page-hero';
import SectionHeading from '@/components/marketing/section-heading';
import { Button } from '@/components/ui/button';
import {
    formatPrice,
    freeForNowNote,
    plans,
    type Currency,
} from '@/config/plans';
import { useCurrency } from '@/hooks/use-currency';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { contact, register } from '@/routes';
import type { PlanKey } from '@/types';

type BillingPeriod = 'monthly' | 'yearly';

/** The sign-up button on each plan's card. */
const ctas: Record<PlanKey, string> = {
    hobby: 'Start for free',
    pro: 'Get started',
    team: 'Get started',
};

/** The plan the page points most visitors to. */
const featuredPlan: PlanKey = 'pro';

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
    { label: 'Database backups to S3 or R2', values: [false, true, true] },
    { label: 'Queue workers and cron jobs', values: [true, true, true] },
    { label: 'Uptime and certificate monitoring', values: [false, true, true] },
    { label: 'Laravel Octane', values: [false, true, true] },
    { label: 'Browser terminal', values: [true, true, true] },
    { label: 'Role-based access', values: [false, false, true] },
    { label: 'Support', values: ['Community', 'Email', 'Priority'] },
];

/** The billing questions, with prices in the visitor's currency. */
const billingFaqs = (currency: Currency): FaqItem[] => [
    {
        question: 'Is the price per server?',
        answer: 'No. Paid plans include unlimited servers for one flat price. Your cloud provider bills you separately for the servers themselves, at their own rates.',
    },
    {
        question: 'Do I need a card to start?',
        answer: 'No. The Hobby plan is free for now and does not ask for a card.',
    },
    {
        question: 'Will the Hobby plan stay free?',
        answer: `No. Hobby is free for now. ${freeForNowNote(currency)}.`,
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
    const currency = useCurrency();

    return (
        <MarketingLayout
            title="Pricing"
            description="Simple, flat pricing for flitops. Unlimited servers on paid plans, and your first server free for now."
        >
            <PageHero
                eyebrow="Pricing"
                title="Flat pricing. Unlimited servers."
                description="One price for the platform, however large your fleet grows. You pay your cloud provider directly for the servers."
            >
                <div className="mt-12">
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
                </div>
            </PageHero>

            <section className="px-6 lg:px-8">
                <div className="mx-auto grid max-w-7xl overflow-hidden rounded-2xl border border-border lg:grid-cols-3">
                    {plans.map((plan) => {
                        const price = plan.prices[currency][billingPeriod];
                        const isFeatured = plan.key === featuredPlan;

                        return (
                            <div
                                key={plan.name}
                                className={cn(
                                    'flex flex-col border-border p-8 not-last:border-b sm:p-10 lg:not-last:border-r lg:not-last:border-b-0',
                                    isFeatured && 'bg-muted',
                                )}
                            >
                                <div className="flex items-center justify-between">
                                    <h2 className="text-2xl font-medium tracking-tight">
                                        {plan.name}
                                    </h2>
                                    {isFeatured && (
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
                                        {price === 0 ? 'for now' : '/ month'}
                                    </span>
                                </p>
                                <p className="mt-2 h-5 text-sm text-muted-foreground">
                                    {price === 0 && freeForNowNote(currency)}
                                    {price > 0 &&
                                        billingPeriod === 'yearly' &&
                                        `Billed ${formatPrice(price * 12, currency)} yearly`}
                                </p>

                                <Button
                                    asChild
                                    size="lg"
                                    variant={isFeatured ? 'default' : 'outline'}
                                    className="mt-8 h-12 text-base"
                                >
                                    <Link href={register()}>
                                        {ctas[plan.key]}
                                    </Link>
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
                    <FaqList items={billingFaqs(currency)} />
                </div>
            </section>

            <CallToAction />
        </MarketingLayout>
    );
}
