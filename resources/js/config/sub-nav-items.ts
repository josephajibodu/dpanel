import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import type { SubNavItem } from '@/types';
import type { Server } from '@/types/server';

export function getServerSubNavItems(
    teamSlug: string,
    server: Pick<
        Server,
        | 'id'
        | 'has_php'
        | 'supports_sites'
        | 'database_type'
        | 'accepts_connections'
    >,
): SubNavItem[] {
    const base = `/${teamSlug}/servers/${server.id}`;
    const items: SubNavItem[] = [
        { title: 'Overview', href: base, exactMatch: true },
        { title: 'Sites', href: `${base}/sites` },
        { title: 'Databases', href: `${base}/databases` },
        { title: 'Connections', href: `${base}/connections` },
        { title: 'PHP', href: `${base}/php` },
        { title: 'Storage', href: `${base}/storage` },
        { title: 'Processes', href: `${base}/processes` },
        { title: 'Runtime', href: `${base}/runtime` },
        { title: 'Observe', href: `${base}/observe` },
        {
            title: 'Terminal',
            href: `${base}/terminal`,
            permission: 'access_servers_as_admin',
        },
        { title: 'Settings', href: `${base}/settings` },
    ];

    const hidden = new Set<string>();

    if (!server.supports_sites) {
        hidden.add('Sites');
    }

    if (server.database_type === null) {
        hidden.add('Databases');
    }

    if (!server.accepts_connections) {
        hidden.add('Connections');
    }

    if (!server.has_php) {
        hidden.add('PHP');
    }

    return items.filter((item) => !hidden.has(item.title));
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
        { title: 'Web Server', href: `${base}/nginx` },
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
