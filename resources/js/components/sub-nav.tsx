import { Link } from '@inertiajs/react';

import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { useActiveUrl } from '@/hooks/use-active-url';
import { cn, toUrl } from '@/lib/utils';
import type { SubNavItem as SubNavItemType } from '@/types';

interface SubNavProps {
    items: SubNavItemType[];
    /**
     * Optional href for the item that should show the "pill" (secondary selected) style.
     * When the current URL matches an item's href, that item gets the underline (primary active) style instead.
     */
    selectedHref?: string;
    className?: string;
}

const activeUnderlineStyles =
    'text-foreground dark:bg-transparent dark:text-neutral-100';
const selectedPillStyles =
    'rounded-md bg-muted/80 text-foreground dark:bg-neutral-800 dark:text-neutral-100';
const inactiveStyles = 'text-muted-foreground';

export function SubNav({ items, selectedHref, className }: SubNavProps) {
    const { urlIsActive } = useActiveUrl();

    return (
        <nav
            className={cn(
                'border-b border-sidebar-border/80 bg-background',
                className,
            )}
        >
            <div className="mx-auto flex h-12 w-full items-center px-4 md:max-w-7xl">
                <NavigationMenu className="flex h-full w-full justify-start">
                    <NavigationMenuList className="flex h-full items-center gap-1 space-x-0">
                        {items.map((item) => {
                            const isActive = urlIsActive(item.href);
                            const isSelected =
                                !isActive &&
                                selectedHref &&
                                toUrl(item.href) === toUrl(selectedHref);

                            return (
                                <NavigationMenuItem
                                    key={item.href}
                                    className="relative flex h-full items-center"
                                >
                                    <Link
                                        href={item.href}
                                        className={cn(
                                            navigationMenuTriggerStyle(),
                                            'h-9 cursor-pointer px-3',
                                            isActive && activeUnderlineStyles,
                                            isSelected && selectedPillStyles,
                                            !isActive &&
                                                !isSelected &&
                                                inactiveStyles,
                                        )}
                                    >
                                        {item.title}
                                    </Link>
                                    {isActive && (
                                        <div
                                            className="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-foreground dark:bg-white"
                                            aria-hidden
                                        />
                                    )}
                                </NavigationMenuItem>
                            );
                        })}
                    </NavigationMenuList>
                </NavigationMenu>
            </div>
        </nav>
    );
}
