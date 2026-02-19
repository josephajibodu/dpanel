import { type ReactNode } from 'react';

import { SubNav } from '@/components/sub-nav';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem, type SubNavItem } from '@/types';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /** Sub-navigation items. Omit to hide the sub-nav (e.g. servers index). */
    subNavItems?: SubNavItem[];
    /** Optional href for the item that shows the pill (secondary selected) style. */
    subNavSelectedHref?: string;
}

export default ({
    children,
    breadcrumbs,
    subNavItems,
    subNavSelectedHref,
    ...props
}: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
        {subNavItems && subNavItems.length > 0 && (
            <SubNav
                items={subNavItems}
                selectedHref={subNavSelectedHref}
            />
        )}
        {children}
    </AppLayoutTemplate>
);
