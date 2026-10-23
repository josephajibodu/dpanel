import { usePage } from '@inertiajs/react';

import { type SharedData, type TeamPermission } from '@/types';

/**
 * Returns a `can(permission)` check for the current team, used to hide
 * controls the user can't use. The server still authorizes every action.
 */
export function usePermission() {
    const { permissions } = usePage<SharedData>().props.auth;

    return (permission: TeamPermission): boolean =>
        permissions?.[permission] ?? false;
}
