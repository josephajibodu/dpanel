import { Link } from '@inertiajs/react';
import { Fragment } from 'react';

import { findServerBreadcrumbIndex, ServerBreadcrumbSwitcher } from '@/components/server-switcher';
import { findSiteBreadcrumbIndex, SiteBreadcrumbSwitcher } from '@/components/site-switcher';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useActiveUrl } from '@/hooks/use-active-url';
import { useMainNav } from '@/hooks/use-main-nav';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    actionsRef,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    /** Receives the element that `PageActions` renders a page's buttons into. */
    actionsRef?: (element: HTMLElement | null) => void;
}) {
    const serverIndex = findServerBreadcrumbIndex(breadcrumbs);
    const siteIndex = findSiteBreadcrumbIndex(breadcrumbs);
    const { groups, settings } = useMainNav();
    const { urlIsActive } = useActiveUrl();

    // The sidebar link this page sits under lends its icon to the page title.
    const section = [...groups.flatMap((group) => group.items), settings].find(
        (item) => item.isActive ?? urlIsActive(item.href),
    );
    const SectionIcon = section?.icon;

    return (
        <header className="flex h-13 shrink-0 items-center gap-2 px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />

                {SectionIcon && (
                    <SectionIcon className="size-[18px] shrink-0 text-muted-foreground" />
                )}

                {breadcrumbs.length > 0 && (
                    <Breadcrumb>
                        <BreadcrumbList>
                            {breadcrumbs.map((item, index) => {
                                const isLast = index === breadcrumbs.length - 1;
                                const isServerItem = index === serverIndex;
                                const isSiteItem = index === siteIndex;
                                const isSwitcherItem = isServerItem || isSiteItem;

                                return (
                                    <Fragment key={index}>
                                        {/* Separator before every item except the first */}
                                        {index > 0 && !isSwitcherItem && (
                                            <BreadcrumbSeparator />
                                        )}

                                        {isServerItem ? (
                                            <ServerBreadcrumbSwitcher
                                                breadcrumb={item}
                                                isLast={isLast}
                                            />
                                        ) : isSiteItem ? (
                                            <SiteBreadcrumbSwitcher
                                                breadcrumb={item}
                                                isLast={isLast}
                                            />
                                        ) : (
                                            <BreadcrumbItem>
                                                {isLast ? (
                                                    <BreadcrumbPage>{item.title}</BreadcrumbPage>
                                                ) : (
                                                    <BreadcrumbLink asChild>
                                                        <Link href={item.href}>{item.title}</Link>
                                                    </BreadcrumbLink>
                                                )}
                                            </BreadcrumbItem>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </BreadcrumbList>
                    </Breadcrumb>
                )}
            </div>

            <div
                ref={actionsRef}
                className="flex shrink-0 items-center gap-2"
            />
        </header>
    );
}
