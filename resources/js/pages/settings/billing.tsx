import { Head, Link } from '@inertiajs/react';
import { CheckIcon } from '@phosphor-icons/react';

import { EmptyState } from '@/components/empty-state';
import {
    SettingsRow,
    SettingsRows,
    SettingsSection,
} from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
    formatPrice,
    freeForNowNote,
    plans,
    type PlanDetails,
} from '@/config/plans';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { contact } from '@/routes';
import { show } from '@/routes/billing';
import { type BreadcrumbItem, type PlanKey } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Billing settings',
        href: show().url,
    },
];

interface Props {
    currentPlan: PlanKey;
    /** Servers across every team the user owns, which is what the limit counts. */
    serverCount: number;
    /** Null when the plan has no server limit. */
    serverLimit: number | null;
}

/**
 * The user's own plan and, once payments are live, how they pay for it. The
 * plan changes, card and bills are laid out but not wired up yet.
 */
export default function Billing({
    currentPlan,
    serverCount,
    serverLimit,
}: Props) {
    const currentIndex = plans.findIndex((plan) => plan.key === currentPlan);
    const current = plans[currentIndex];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSettingsSubNavItems()}
        >
            <Head title="Billing settings" />

            <SettingsLayout
                title="Billing"
                description="Your plan covers every team you own. Teams you’ve joined run on their owner’s plan."
            >
                <SettingsSection
                    title="Plan"
                    description={planSummary(
                        current.name,
                        serverCount,
                        serverLimit,
                    )}
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-0.5">
                            <h3 className="text-lg font-semibold">
                                {current.name}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {current.description}
                            </p>
                        </div>
                        <PlanPrice plan={current} className="sm:text-right" />
                    </div>
                    <Highlights
                        plan={current}
                        className="sm:grid-cols-2 lg:grid-cols-3"
                    />
                </SettingsSection>

                <SettingsSection
                    title="Change plan"
                    description={
                        <>
                            Paying online is coming soon. Until then,{' '}
                            <Link
                                href={contact()}
                                className="text-foreground underline underline-offset-4"
                            >
                                contact us
                            </Link>{' '}
                            to change your plan.
                        </>
                    }
                    bare
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {plans.map(
                            (plan, index) =>
                                index !== currentIndex && (
                                    <PlanCard
                                        key={plan.key}
                                        plan={plan}
                                        isUpgrade={index > currentIndex}
                                    />
                                ),
                        )}
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Payment method"
                    description="The card your bills are charged to."
                    flush
                >
                    <SettingsRows>
                        <SettingsRow
                            label="No card on file"
                            description="You’ll add a card when you pay for a plan."
                        >
                            <Button variant="outline" disabled>
                                Add card
                            </Button>
                        </SettingsRow>
                    </SettingsRows>
                </SettingsSection>

                <SettingsSection
                    title="Bills"
                    description="Every payment for your plan, each with a receipt."
                    bare
                >
                    <EmptyState
                        title="No bills yet"
                        description="Your bills show here once you pay for a plan."
                    />
                </SettingsSection>
            </SettingsLayout>
        </AppLayout>
    );
}

/** Which plan the user is on and how much of its server allowance they use. */
function planSummary(
    planName: string,
    serverCount: number,
    serverLimit: number | null,
): string {
    const servers = (count: number) =>
        `${count} ${count === 1 ? 'server' : 'servers'}`;

    if (serverLimit === null) {
        return `You’re on the ${planName} plan, with no limit on servers. You have ${servers(serverCount)}.`;
    }

    return `You’re on the ${planName} plan, using ${serverCount} of its ${servers(serverLimit)}.`;
}

/** A plan's monthly price in dollars, with the naira price under it. */
function PlanPrice({
    plan,
    className,
}: {
    plan: PlanDetails;
    className?: string;
}) {
    const { usd, ngn } = plan.prices;

    return (
        <div className={className}>
            <p className="text-2xl font-semibold tracking-tight tabular-nums">
                {usd.monthly === 0
                    ? 'Free for now'
                    : formatPrice(usd.monthly, 'usd')}
                {usd.monthly > 0 && (
                    <span className="ml-1 text-sm font-normal tracking-normal text-muted-foreground">
                        / month
                    </span>
                )}
            </p>
            <p className="text-xs text-muted-foreground">
                {ngn.monthly === 0
                    ? freeForNowNote('usd', 'ngn')
                    : `or ${formatPrice(ngn.monthly, 'ngn')} a month`}
            </p>
        </div>
    );
}

/** What a plan includes, as a ticked list under a dividing line. */
function Highlights({
    plan,
    className,
}: {
    plan: PlanDetails;
    className?: string;
}) {
    return (
        <ul
            className={cn(
                'mt-5 grid gap-x-6 gap-y-2 border-t pt-5 text-sm',
                className,
            )}
        >
            {plan.highlights.map((highlight) => (
                <li key={highlight} className="flex gap-2">
                    <CheckIcon className="mt-0.5 size-4 shrink-0" />
                    {highlight}
                </li>
            ))}
        </ul>
    );
}

/** A plan the user could move to, with the button that will switch them. */
function PlanCard({
    plan,
    isUpgrade,
}: {
    plan: PlanDetails;
    isUpgrade: boolean;
}) {
    return (
        <div className="flex flex-col rounded-2xl bg-card p-5 text-card-foreground shadow-card">
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-0.5">
                    <h3 className="font-semibold">{plan.name}</h3>
                    <p className="text-sm text-muted-foreground">
                        {plan.description}
                    </p>
                </div>
                <PlanPrice plan={plan} className="shrink-0 text-right" />
            </div>

            <Highlights plan={plan} />

            <div className="mt-auto pt-5">
                <Button
                    variant={isUpgrade ? 'default' : 'outline'}
                    className="w-full"
                    disabled
                >
                    {isUpgrade
                        ? `Upgrade to ${plan.name}`
                        : `Switch to ${plan.name}`}
                </Button>
            </div>
        </div>
    );
}
