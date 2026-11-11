import { type PropsWithChildren, type ReactNode } from 'react';

// Sidebar nav is intentionally disabled in favour of the top sub-nav.
// To re-enable it, restore the aside + nav block from git history.

export default function SettingsLayout({
    children,
    title,
    description,
    avatar,
}: PropsWithChildren<{
    title: string;
    description?: ReactNode;
    /** Initials shown in a tile beside the title, on pages about a person or a team. */
    avatar?: string;
}>) {
    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    return (
        <div className="mx-auto w-full max-w-3xl px-4 pt-8 pb-12">
            <header className="mb-8 flex items-center gap-3">
                {avatar && (
                    <span
                        className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-neutral-200 text-sm font-semibold dark:bg-neutral-700"
                        aria-hidden
                    >
                        {avatar}
                    </span>
                )}
                <div className="min-w-0">
                    <h1 className="truncate text-[1.75rem] leading-9 font-semibold tracking-tight">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            </header>

            <div className="flex flex-col gap-10">{children}</div>
        </div>
    );
}
