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
        <div className="mx-auto w-full max-w-3xl px-4 py-6">
            <Heading title={title} description={description} />

            <div className="space-y-8">{children}</div>
        </div>
    );
}
