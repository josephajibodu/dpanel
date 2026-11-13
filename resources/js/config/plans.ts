import type { PlanKey } from '@/types';

export type Currency = 'usd' | 'ngn';

/** Monthly price in each currency, with the yearly figure as a per-month rate. */
export type PlanPrice = { monthly: number; yearly: number };

export interface PlanDetails {
    key: PlanKey;
    name: string;
    description: string;
    prices: Record<Currency, PlanPrice>;
    highlights: string[];
}

/**
 * Every plan, cheapest first, as the pricing page and the billing settings
 * show it. The limits each plan enforces live in App\Enums\Plan.
 */
export const plans: PlanDetails[] = [
    {
        key: 'hobby',
        name: 'Hobby',
        description: 'For side projects and trying flitops on a real server.',
        prices: {
            usd: { monthly: 0, yearly: 0 },
            ngn: { monthly: 0, yearly: 0 },
        },
        highlights: [
            '1 server',
            'Unlimited sites and deployments',
            "Let's Encrypt SSL",
            'Browser terminal',
            'Community support',
        ],
    },
    {
        key: 'pro',
        name: 'Pro',
        description: 'For developers and freelancers running production apps.',
        prices: {
            usd: { monthly: 5, yearly: 4 },
            ngn: { monthly: 5000, yearly: 4000 },
        },
        highlights: [
            'Unlimited servers',
            'Scheduled database backups',
            'Uptime monitoring',
            'Email support',
        ],
    },
    {
        key: 'team',
        name: 'Team',
        description: 'For agencies and teams sharing a fleet.',
        prices: {
            usd: { monthly: 15, yearly: 12 },
            ngn: { monthly: 15000, yearly: 12000 },
        },
        highlights: [
            'Everything in Pro',
            'Unlimited team members',
            'Owner, admin, developer, and viewer roles',
            'Multiple teams for clients',
            'Priority support',
        ],
    },
];

/** What Hobby will cost a month once payments are live. */
const hobbyLaterPrice: Record<Currency, number> = { usd: 2, ngn: 2000 };

/**
 * Shown beside Hobby while it costs nothing. It is free only until payments
 * are live, so nothing should call it free forever.
 */
export function freeForNowNote(currency: Currency): string {
    return `Will be ${formatPrice(hobbyLaterPrice[currency], currency)} a month later`;
}

export function formatPrice(amount: number, currency: Currency): string {
    return new Intl.NumberFormat(currency === 'ngn' ? 'en-NG' : 'en-US', {
        style: 'currency',
        currency: currency.toUpperCase(),
        maximumFractionDigits: 0,
    }).format(amount);
}
