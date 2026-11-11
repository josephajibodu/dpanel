import { type PropsWithChildren, useState } from 'react';

import { AlertBanner } from '@/components/alert-banner';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PageActionsContext } from '@/components/page-actions';
import { SubNav } from '@/components/sub-nav';
import { type BreadcrumbItem, type SubNavItem } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    subNavItems = [],
    subNavSelectedHref,
}: PropsWithChildren<{
    breadcrumbs?: BreadcrumbItem[];
    subNavItems?: SubNavItem[];
    subNavSelectedHref?: string;
}>) {
    const [actionsElement, setActionsElement] = useState<HTMLElement | null>(
        null,
    );

    return (
        <AppShell variant="sidebar">
            <AppSidebar
                subNavItems={subNavItems}
                subNavSelectedHref={subNavSelectedHref}
            />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actionsRef={setActionsElement}
                />
                {/* The sidebar lists these pages; this row stands in for it on
                    phones and when the sidebar is collapsed to icons. */}
                {subNavItems.length > 0 && (
                    <SubNav
                        items={subNavItems}
                        selectedHref={subNavSelectedHref}
                        className="md:hidden md:group-has-data-[collapsible=icon]/sidebar-wrapper:block"
                    />
                )}
                <AlertBanner />
                <PageActionsContext value={actionsElement}>
                    {children}
                </PageActionsContext>
            </AppContent>
        </AppShell>
    );
}
