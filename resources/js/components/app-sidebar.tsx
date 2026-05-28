import { Link, usePage } from '@inertiajs/react';
import { BookOpen, CloudIcon, CodeIcon, Folder, KeyIcon, LayoutGrid, ServerIcon } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
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
import { dashboard } from '@/routes';
import { type NavItem, type SharedData } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { currentTeam } = usePage<SharedData>().props;
    const slug = currentTeam?.slug ?? '';

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Servers',
            href: `/${slug}/servers`,
            icon: ServerIcon,
        },
        {
            title: 'SSH Keys',
            href: '/ssh-keys',
            icon: KeyIcon,
        },
        {
            title: 'Provider Accounts',
            href: `/${slug}/provider-accounts`,
            icon: CloudIcon,
        },
        {
            title: 'Source Control',
            href: '/source-control',
            icon: CodeIcon,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                {/*
                  Expanded: logo link + team switcher side by side in one row.
                  Collapsed (icon mode): logo link on top, compact initials switcher below.
                */}
                <div className="flex items-center gap-2 transition-[gap] duration-200 ease-linear group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-1">
                    <Link
                        href={dashboard()}
                        prefetch
                        className="bg-sidebar-primary text-sidebar-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-lg transition-opacity hover:opacity-80"
                    >
                        <AppLogoIcon className="size-4 fill-current text-white dark:text-black" />
                    </Link>
                    <TeamSwitcher />
                </div>
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
