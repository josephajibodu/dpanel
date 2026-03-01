import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import type { SubNavItem } from '@/types';

export function getServerSubNavItems(serverId: number | string): SubNavItem[] {
    const base = `/servers/${serverId}`;
    return [
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
}

export function getSiteSubNavItems(
    serverId: number | string,
    siteId: number | string,
): SubNavItem[] {
    const base = `/servers/${serverId}/sites/${siteId}`;
    return [
        { title: 'Overview', href: base, exactMatch: true },
        { title: 'Deployments', href: `${base}/deployments` },
        { title: 'Commands', href: `${base}/command-runs` },
        { title: 'Environment', href: `${base}/environment` },
        { title: 'Deploy Script', href: `${base}/deploy-script` },
    ];
}

export function getSettingsSubNavItems(): SubNavItem[] {
    return [
        { title: 'Profile', href: edit().url },
        { title: 'Password', href: editPassword().url },
        { title: 'Two-Factor Auth', href: show.url() },
        { title: 'Appearance', href: editAppearance().url },
    ];
}
