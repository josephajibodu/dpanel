import { Link } from '@inertiajs/react';

import { useActiveUrl } from '@/hooks/use-active-url';
import { usePermission } from '@/hooks/use-permission';
import { cn, toUrl } from '@/lib/utils';
import type { SubNavItem as SubNavItemType } from '@/types';

interface SubNavProps {
    items: SubNavItemType[];
    /**
     * Optional href for the item that should show the lighter "selected" pill.
     * When the current URL matches an item's href, that item gets the stronger active pill instead.
     */
    selectedHref?: string;
    className?: string;
}

const activeStyles = 'bg-secondary text-foreground';
const selectedStyles = 'bg-muted text-foreground';
const inactiveStyles =
    'text-muted-foreground hover:bg-foreground/5 hover:text-foreground';

export function SubNav({ items, selectedHref, className }: SubNavProps) {
    const { urlIsActive } = useActiveUrl();
    const can = usePermission();
    const visibleItems = items.filter(
        (item) => !item.permission || can(item.permission),
    );

    return (
        <nav className={cn('border-b bg-background', className)}>
            <div className="flex w-full items-center gap-1 overflow-x-auto px-4 pb-3">
                {visibleItems.map((item) => {
                    const isActive = urlIsActive(item.href, undefined, {
                        exact: item.exactMatch,
                    });
                    const isSelected =
                        !isActive &&
                        selectedHref &&
                        toUrl(item.href) === toUrl(selectedHref);

                    return (
                        <Link
                            key={toUrl(item.href)}
                            href={item.href}
                            aria-current={isActive ? 'page' : undefined}
                            className={cn(
                                'inline-flex h-7 shrink-0 items-center rounded-full px-3 text-[13px] font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                isActive && activeStyles,
                                isSelected && selectedStyles,
                                !isActive && !isSelected && inactiveStyles,
                            )}
                        >
                            {item.title}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
