import { Link } from '@inertiajs/react';

import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/hooks/use-active-url';
import { type NavGroup } from '@/hooks/use-main-nav';
import { usePermission } from '@/hooks/use-permission';
import { cn, toUrl } from '@/lib/utils';
import { type SubNavItem } from '@/types';

interface NavMainProps {
    groups: NavGroup[];
    /** The current section's pages, listed under whichever link is active. */
    subNavItems?: SubNavItem[];
    /** The sub-page to mark when the current URL matches none of them. */
    subNavSelectedHref?: string;
    className?: string;
}

export function NavMain({
    groups,
    subNavItems = [],
    subNavSelectedHref,
    className,
}: NavMainProps) {
    const { urlIsActive } = useActiveUrl();
    const { state, isMobile } = useSidebar();
    const can = usePermission();

    const visibleSubItems = subNavItems.filter(
        (item) => !item.permission || can(item.permission),
    );
    const showsSubItems =
        visibleSubItems.length > 0 && (state === 'expanded' || isMobile);

    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup
                    key={group.label ?? index}
                    className={cn('px-2 py-1', className)}
                >
                    {group.label && (
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                    )}
                    <SidebarMenu className="gap-0.5">
                        {group.items.map((item) => {
                            const isActive =
                                item.isActive ?? urlIsActive(item.href);
                            const hasSubItems = isActive && showsSubItems;

                            return (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isActive && !hasSubItems}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={item.href} prefetch>
                                            {item.icon && (
                                                <item.icon
                                                    weight={
                                                        isActive
                                                            ? 'fill'
                                                            : 'regular'
                                                    }
                                                />
                                            )}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>

                                    {hasSubItems && (
                                        <SidebarMenuSub className="mx-0 translate-x-0 gap-0.5 border-l-0 px-0 pt-0.5 pb-1">
                                            {visibleSubItems.map((subItem) => {
                                                const isSubActive = urlIsActive(
                                                    subItem.href,
                                                    undefined,
                                                    {
                                                        exact: subItem.exactMatch,
                                                    },
                                                );
                                                const isSubSelected =
                                                    !isSubActive &&
                                                    subNavSelectedHref !==
                                                        undefined &&
                                                    toUrl(subItem.href) ===
                                                        toUrl(
                                                            subNavSelectedHref,
                                                        );

                                                return (
                                                    <SidebarMenuSubItem
                                                        key={subItem.href}
                                                    >
                                                        <SidebarMenuSubButton
                                                            asChild
                                                            isActive={
                                                                isSubActive ||
                                                                isSubSelected
                                                            }
                                                            className="h-[30px] translate-x-0 rounded-[10px] pl-[34px] text-sidebar-foreground/70 data-[active=true]:font-medium"
                                                        >
                                                            <Link
                                                                href={
                                                                    subItem.href
                                                                }
                                                                aria-current={
                                                                    isSubActive
                                                                        ? 'page'
                                                                        : undefined
                                                                }
                                                            >
                                                                <span>
                                                                    {
                                                                        subItem.title
                                                                    }
                                                                </span>
                                                            </Link>
                                                        </SidebarMenuSubButton>
                                                    </SidebarMenuSubItem>
                                                );
                                            })}
                                        </SidebarMenuSub>
                                    )}
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
