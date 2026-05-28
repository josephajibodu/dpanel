import { usePage } from '@inertiajs/react';
import { type SharedData } from '@/types';

export function useTeamPath() {
    const { currentTeam } = usePage<SharedData>().props;

    return (path: string) => `/${currentTeam?.slug ?? ''}${path}`;
}
