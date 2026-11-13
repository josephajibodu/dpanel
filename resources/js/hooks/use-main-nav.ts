import { usePage } from '@inertiajs/react';
import {
    BookOpenIcon,
    CloudIcon,
    CodeIcon,
    GearIcon,
    HardDriveIcon,
    HardDrivesIcon,
    HouseIcon,
    KeyIcon,
} from '@phosphor-icons/react';

import { useTeamPath } from '@/hooks/use-team-path';
import { edit as editProfile } from '@/routes/profile';
import { type NavItem } from '@/types';

export interface NavGroup {
    label?: string;
    items: NavItem[];
}

/** The sidebar's links, shared with the top bar so it can show a page's icon. */
export function useMainNav(): {
    groups: NavGroup[];
    settings: NavItem;
    documentation: NavItem;
} {
    const teamPath = useTeamPath();
    const { url } = usePage();

    return {
        groups: [
            {
                items: [
                    {
                        title: 'Dashboard',
                        href: teamPath('/dashboard'),
                        icon: HouseIcon,
                        keywords: ['home', 'overview', 'start'],
                    },
                    {
                        title: 'Servers',
                        href: teamPath('/servers'),
                        icon: HardDrivesIcon,
                        keywords: ['machines', 'vps', 'hosts', 'droplets'],
                    },
                ],
            },
            {
                label: 'Integrations',
                items: [
                    {
                        title: 'SSH Keys',
                        href: teamPath('/ssh-keys'),
                        icon: KeyIcon,
                        keywords: ['public key', 'access', 'login'],
                    },
                    {
                        title: 'Provider Accounts',
                        href: teamPath('/provider-accounts'),
                        icon: CloudIcon,
                        keywords: [
                            'cloud',
                            'digitalocean',
                            'hetzner',
                            'vultr',
                            'aws',
                            'api token',
                        ],
                    },
                    {
                        title: 'Storage Providers',
                        href: teamPath('/storage-providers'),
                        icon: HardDriveIcon,
                        keywords: ['s3', 'bucket', 'backups', 'r2', 'spaces'],
                    },
                    {
                        title: 'Source Control',
                        href: teamPath('/source-control'),
                        icon: CodeIcon,
                        keywords: [
                            'github',
                            'gitlab',
                            'bitbucket',
                            'git',
                            'repositories',
                        ],
                    },
                ],
            },
        ],
        settings: {
            title: 'Settings',
            href: editProfile().url,
            icon: GearIcon,
            isActive: url.startsWith('/settings'),
        },
        documentation: {
            title: 'Documentation',
            href: 'https://docs.flitops.com',
            icon: BookOpenIcon,
            keywords: ['docs', 'help', 'guide', 'support'],
        },
    };
}
