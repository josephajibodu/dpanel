import { usePage } from '@inertiajs/react';

import { type Plan, type SharedData } from '@/types';

/**
 * The current team's plan, used to swap controls it doesn't include for an
 * upgrade note. The server still enforces every limit.
 */
export function usePlan(): Plan | null {
    return usePage<SharedData>().props.plan;
}
