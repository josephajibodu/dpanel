import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavSearch } from '@/components/nav-search';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useMainNav } from '@/hooks/use-main-nav';
import { type SubNavItem } from '@/types';

interface AppSidebarProps {
    /** The current section's pages, listed under the active sidebar link. */
    subNavItems?: SubNavItem[];
    subNavSelectedHref?: string;
}

export function AppSidebar({
    subNavItems,
    subNavSelectedHref,
}: AppSidebarProps) {
    const { groups, settings, documentation } = useMainNav();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <TeamSwitcher />
                <NavSearch />
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    groups={groups}
                    subNavItems={subNavItems}
                    subNavSelectedHref={subNavSelectedHref}
                />
            </SidebarContent>

            <SidebarFooter className="gap-0.5">
                <NavFooter items={[documentation]} className="p-0" />
                <NavMain
                    groups={[{ items: [settings] }]}
                    subNavItems={subNavItems}
                    subNavSelectedHref={subNavSelectedHref}
                    className="p-0"
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
