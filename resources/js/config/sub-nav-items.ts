import { edit as editAppearance } from '@/routes/appearance';
import { show as showBilling } from '@/routes/billing';
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
        {
            title: 'Overview',
            href: base,
            exactMatch: true,
            keywords: ['status', 'metrics', 'cpu', 'memory', 'disk'],
        },
        {
            title: 'Sites',
            href: `${base}/sites`,
            keywords: ['websites', 'apps', 'new site'],
        },
        {
            title: 'Databases',
            href: `${base}/databases`,
            keywords: ['db', 'mysql', 'postgres', 'mariadb', 'database users'],
        },
        {
            title: 'Connections',
            href: `${base}/connections`,
            keywords: ['connected servers', 'redis', 'remote access'],
        },
        {
            title: 'PHP',
            href: `${base}/php`,
            keywords: ['php version', 'extensions', 'php settings', 'fpm'],
        },
        {
            title: 'Processes',
            href: `${base}/processes`,
            keywords: ['workers', 'cron jobs', 'scheduler', 'queues'],
        },
        {
            title: 'Runtime',
            href: `${base}/runtime`,
            keywords: ['node', 'nodejs', 'node version'],
        },
        {
            title: 'Terminal',
            href: `${base}/terminal`,
            permission: 'access_servers_as_admin',
            keywords: ['ssh', 'shell', 'console', 'command line'],
        },
        {
            title: 'Settings',
            href: `${base}/settings`,
            keywords: [
                'ssh port',
                'transfer',
                'disconnect',
                'destroy',
                'delete',
            ],
        },
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
        {
            title: 'Overview',
            href: base,
            exactMatch: true,
            keywords: ['status', 'uptime'],
        },
        {
            title: 'Deployments',
            href: `${base}/deployments`,
            keywords: ['deploy', 'releases', 'history', 'auto deploy'],
        },
        {
            title: 'Commands',
            href: `${base}/command-runs`,
            keywords: ['run command', 'artisan', 'cli'],
        },
        {
            title: 'Processes',
            href: `${base}/processes`,
            keywords: ['workers', 'queues', 'cron jobs', 'scheduler'],
        },
        {
            title: 'Environment',
            href: `${base}/environment`,
            permission: 'view_secrets',
            keywords: ['.env', 'env', 'variables', 'secrets', 'config'],
        },
        { title: 'Database', href: `${base}/database`, keywords: ['db'] },
        {
            title: 'Deploy Script',
            href: `${base}/deploy-script`,
            keywords: ['build', 'deploy commands'],
        },
        {
            title: 'Web Server',
            href: `${base}/nginx`,
            keywords: ['nginx', 'vhost', 'server block', 'config'],
        },
        {
            title: 'Domains',
            href: `${base}/domains`,
            keywords: ['ssl', 'https', 'certificates', 'aliases', 'dns'],
        },
        {
            title: 'Logs',
            href: `${base}/logs`,
            keywords: ['errors', 'error log'],
        },
    ];
}

export function getSettingsSubNavItems(): SubNavItem[] {
    return [
        {
            title: 'Profile',
            href: edit().url,
            keywords: ['name', 'email', 'account', 'git name'],
        },
        {
            title: 'Password',
            href: editPassword().url,
            keywords: ['change password', 'security'],
        },
        {
            title: 'Two-factor auth',
            href: show.url(),
            keywords: [
                '2fa',
                'mfa',
                'authenticator',
                'security',
                'recovery codes',
            ],
        },
        {
            title: 'Appearance',
            href: editAppearance().url,
            keywords: ['theme', 'dark mode', 'light mode'],
        },
        {
            title: 'Team',
            href: '/settings/team',
            keywords: ['members', 'invite', 'people', 'roles', 'users'],
        },
        {
            title: 'Billing',
            href: showBilling.url(),
            keywords: [
                'plan',
                'subscription',
                'upgrade',
                'payment',
                'invoices',
            ],
        },
    ];
}
