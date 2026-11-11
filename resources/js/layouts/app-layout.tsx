import { type ReactNode } from 'react';

import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem, type SubNavItem } from '@/types';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /** The section's pages, listed in the sidebar under the active link. Omit on pages with none. */
    subNavItems?: SubNavItem[];
    /** Optional href for the sub-page to mark when the current URL matches none of them. */
    subNavSelectedHref?: string;
}

export default ({
    children,
    breadcrumbs,
    subNavItems,
    subNavSelectedHref,
    ...props
}: AppLayoutProps) => (
    <AppLayoutTemplate
        breadcrumbs={breadcrumbs}
        subNavItems={subNavItems}
        subNavSelectedHref={subNavSelectedHref}
        {...props}
    >
        {children}
    </AppLayoutTemplate>
);
