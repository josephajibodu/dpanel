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
import { cn } from '@/lib/utils';
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

    // Below laptop width the bar has room for one breadcrumb: the site or
    // server switcher when the page has one (the tabs or the sidebar name the
    // page), otherwise the page itself.
    const compactIndex =
        siteIndex >= 0
            ? siteIndex
            : serverIndex >= 0
              ? serverIndex
              : breadcrumbs.length - 1;
    const isCompactSwitcher =
        compactIndex === siteIndex || compactIndex === serverIndex;

    return (
        <header className="flex h-13 shrink-0 items-center gap-2 px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1 shrink-0" />

                {SectionIcon && (
                    <SectionIcon
                        className={cn(
                            'size-[18px] shrink-0 text-muted-foreground',
                            // The switcher carries its own icon.
                            isCompactSwitcher && 'max-lg:hidden',
                        )}
                    />
                )}

                {breadcrumbs.length > 0 && (
                    <Breadcrumb className="min-w-0">
                        <BreadcrumbList className="flex-nowrap">
                            {breadcrumbs.map((item, index) => {
                                const isLast = index === breadcrumbs.length - 1;

                                return (
                                    <Fragment key={index}>
                                        {index > 0 && (
                                            <BreadcrumbSeparator className="max-lg:hidden" />
                                        )}

                                        <BreadcrumbItem
                                            className={cn(
                                                'min-w-0',
                                                index !== compactIndex &&
                                                    'max-lg:hidden',
                                            )}
                                        >
                                            {index === serverIndex ? (
                                                <ServerBreadcrumbSwitcher
                                                    breadcrumb={item}
                                                    isLast={isLast}
                                                />
                                            ) : index === siteIndex ? (
                                                <SiteBreadcrumbSwitcher
                                                    breadcrumb={item}
                                                    isLast={isLast}
                                                />
                                            ) : isLast ? (
                                                <BreadcrumbPage className="truncate">
                                                    {item.title}
                                                </BreadcrumbPage>
                                            ) : (
                                                <BreadcrumbLink
                                                    asChild
                                                    className="truncate"
                                                >
                                                    <Link href={item.href}>
                                                        {item.title}
                                                    </Link>
                                                </BreadcrumbLink>
                                            )}
                                        </BreadcrumbItem>
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
