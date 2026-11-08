import { type PropsWithChildren } from 'react';

import Heading from '@/components/heading';

// Sidebar nav is intentionally disabled in favour of the top sub-nav.
// To re-enable it, restore the aside + nav block from git history.

export default function SettingsLayout({
    children,
    title = 'Settings',
    description = 'Manage your account, security, and preferences.',
}: PropsWithChildren<{ title?: string; description?: string }>) {
    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    return (
        <div className="px-4 py-6">
            <Heading title={title} description={description} />

            <div className="mt-6 max-w-3xl space-y-6">{children}</div>
        </div>
    );
}
