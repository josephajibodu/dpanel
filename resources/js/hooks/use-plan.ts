import { usePage } from '@inertiajs/react';

import { pricing } from '@/routes';
import { show as showBilling } from '@/routes/billing';
import { type Plan, type SharedData } from '@/types';

/**
 * The current team's plan, used to swap controls it doesn't include for an
 * upgrade note. The server still enforces every limit.
 */
export function usePlan(): Plan | null {
    return usePage<SharedData>().props.plan;
}

/**
 * Where a "See plans" link goes. The current team runs on its owner's plan,
 * so the owner goes to their billing settings, where they can change it,
 * and everyone else to the pricing page.
 */
export function usePlansHref(): string {
    const { auth, currentTeam } = usePage<SharedData>().props;

    return currentTeam !== null && currentTeam.user_id === auth.user.id
        ? showBilling.url()
        : pricing.url();
}
