import {
    BookOpen,
    CloudIcon,
    CodeIcon,
    Folder,
    HardDriveIcon,
    KeyIcon,
    LayoutGrid,
    ServerIcon,
} from 'lucide-react';

import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useTeamPath } from '@/hooks/use-team-path';
import { type NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://docs.flitops.com',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const teamPath = useTeamPath();

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: teamPath('/dashboard'),
            icon: LayoutGrid,
        },
        {
            title: 'Servers',
            href: teamPath('/servers'),
            icon: ServerIcon,
        },
        {
            title: 'SSH Keys',
            href: teamPath('/ssh-keys'),
            icon: KeyIcon,
        },
        {
            title: 'Provider Accounts',
            href: teamPath('/provider-accounts'),
            icon: CloudIcon,
        },
        {
            title: 'Storage Providers',
            href: teamPath('/storage-providers'),
            icon: HardDriveIcon,
        },
        {
            title: 'Source Control',
            href: teamPath('/source-control'),
            icon: CodeIcon,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <TeamSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
