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
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const serverIndex = findServerBreadcrumbIndex(breadcrumbs);
    const siteIndex = findSiteBreadcrumbIndex(breadcrumbs);

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />

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
        </header>
    );
}
