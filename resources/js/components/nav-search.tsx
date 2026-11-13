import { MagnifyingGlassIcon } from '@phosphor-icons/react';
import { useSyncExternalStore } from 'react';

import { useOpenCommandPalette } from '@/components/command-palette';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

const neverChanges = () => () => {};

/** The sidebar's way into search, showing the shortcut for this computer. */
export function NavSearch() {
    const openSearch = useOpenCommandPalette();
    const { isMobile, setOpenMobile } = useSidebar();
    const isMac = useSyncExternalStore(
        neverChanges,
        () => /Mac|iPhone|iPad/.test(navigator.userAgent),
        () => true,
    );
    const shortcut = isMac ? '⌘K' : 'Ctrl K';

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton
                    tooltip={{ children: `Search (${shortcut})` }}
                    onClick={() => {
                        if (isMobile) {
                            setOpenMobile(false);
                        }

                        openSearch();
                    }}
                >
                    <MagnifyingGlassIcon />
                    <span>Search</span>
                    <kbd className="ml-auto rounded border border-sidebar-border px-1 font-sans text-[11px] leading-4 text-sidebar-foreground/60 group-data-[collapsible=icon]:hidden">
                        {shortcut}
                    </kbd>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
