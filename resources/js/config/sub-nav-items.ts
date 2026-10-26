import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import type { SubNavItem } from '@/types';
import type { Server } from '@/types/server';

export function getServerSubNavItems(
    teamSlug: string,
    server: Pick<Server, 'id' | 'has_php'>,
): SubNavItem[] {
    const base = `/${teamSlug}/servers/${server.id}`;
    const items: SubNavItem[] = [
        { title: 'Overview', href: base, exactMatch: true },
        { title: 'Sites', href: `${base}/sites` },
        { title: 'Databases', href: `${base}/databases` },
        { title: 'PHP', href: `${base}/php` },
        { title: 'Storage', href: `${base}/storage` },
        { title: 'Processes', href: `${base}/processes` },
        { title: 'Runtime', href: `${base}/runtime` },
        { title: 'Observe', href: `${base}/observe` },
        { title: 'Settings', href: `${base}/settings` },
    ];

    return server.has_php
        ? items
        : items.filter((item) => item.title !== 'PHP');
}

export function getSiteSubNavItems(
    teamSlug: string,
    serverId: number | string,
    siteId: number | string,
): SubNavItem[] {
    const base = `/${teamSlug}/servers/${serverId}/sites/${siteId}`;
    return [
        { title: 'Overview', href: base, exactMatch: true },
        { title: 'Deployments', href: `${base}/deployments` },
        { title: 'Commands', href: `${base}/command-runs` },
        { title: 'Processes', href: `${base}/processes` },
        {
            title: 'Environment',
            href: `${base}/environment`,
            permission: 'view_secrets',
        },
        { title: 'Backups', href: `${base}/backups` },
        { title: 'Deploy Script', href: `${base}/deploy-script` },
        { title: 'Nginx', href: `${base}/nginx` },
        { title: 'Domains', href: `${base}/domains` },
    ];
}

export function getSettingsSubNavItems(): SubNavItem[] {
    return [
        { title: 'Profile', href: edit().url },
        { title: 'Password', href: editPassword().url },
        { title: 'Two-Factor Auth', href: show.url() },
        { title: 'Appearance', href: editAppearance().url },
        { title: 'Team', href: '/settings/team' },
    ];
}
