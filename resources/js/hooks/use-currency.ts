import { useSyncExternalStore } from 'react';

import type { Currency } from '@/config/plans';

/**
 * Nigeria's time zone. Devices in Nigeria report it, while neighbouring
 * countries on the same clock report their own city, such as Africa/Douala.
 */
const nigeriaTimeZone = 'Africa/Lagos';

function currencyFromTimeZone(): Currency {
    return Intl.DateTimeFormat().resolvedOptions().timeZone === nigeriaTimeZone
        ? 'ngn'
        : 'usd';
}

/** The time zone doesn't change while a page is open, so there is nothing to listen for. */
const subscribe = () => () => {};

/**
 * The currency to show prices in: naira for visitors in Nigeria and dollars
 * for everyone else. The country comes from the device's time zone, which
 * needs no lookup service and still holds behind a VPN. It only decides what
 * is displayed; a visitor can change it, so charging must not rely on it.
 */
export function useCurrency(): Currency {
    return useSyncExternalStore(
        subscribe,
        currencyFromTimeZone,
        (): Currency => 'usd',
    );
}
