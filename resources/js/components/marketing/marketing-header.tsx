import { Link, usePage } from '@inertiajs/react';
import { ArrowRightIcon, ListIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import {
    about,
    features,
    home,
    login,
    pricing,
    register,
    security,
} from '@/routes';
import { type SharedData } from '@/types';

export const marketingNavigation = [
    { title: 'Features', href: features.url() },
    { title: 'Pricing', href: pricing.url() },
    { title: 'Security', href: security.url() },
    { title: 'About', href: about.url() },
];

/**
 * The wordmark used across the public site: the flitops mark on a brand tile.
 */
export function MarketingWordmark({ className }: { className?: string }) {
    return (
        <Link
            href={home()}
            className={cn('flex items-center gap-2.5', className)}
        >
            <span className="flex size-7 items-center justify-center rounded-[7px] bg-brand text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25)]">
                <AppLogoIcon className="size-4 fill-current" />
            </span>
            <span className="text-[17px] font-semibold tracking-tight">
                flitops
            </span>
        </Link>
    );
}

export default function MarketingHeader({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth, currentTeam } = usePage<SharedData>().props;
    const currentPath = usePage().url.split('?')[0];
    const [hasScrolled, setHasScrolled] = useState(false);

    const dashboardHref = currentTeam
        ? `/${currentTeam.slug}/dashboard`
        : '/dashboard';

    useEffect(() => {
        const updateScrolled = () => setHasScrolled(window.scrollY > 8);

        updateScrolled();
        window.addEventListener('scroll', updateScrolled, { passive: true });

        return () => window.removeEventListener('scroll', updateScrolled);
    }, []);

    return (
        <header
            className={cn(
                'sticky top-0 z-50 border-b transition-colors duration-300',
                hasScrolled
                    ? 'border-border/70 bg-background/80 backdrop-blur-xl'
                    : 'border-transparent bg-transparent',
            )}
        >
            <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                <div className="flex items-center gap-10">
                    <MarketingWordmark />

                    <nav className="hidden items-center gap-1 text-sm md:flex">
                        {marketingNavigation.map((item) => (
                            <Link
                                key={item.title}
                                href={item.href}
                                className={cn(
                                    'rounded-md px-3 py-1.5 transition-colors hover:text-foreground',
                                    currentPath === item.href
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {item.title}
                            </Link>
                        ))}
                    </nav>
                </div>

                <div className="flex items-center gap-2">
                    {auth.user ? (
                        <Button asChild size="sm" className="rounded-full px-4">
                            <Link href={dashboardHref}>
                                Open dashboard
                                <ArrowRightIcon />
                            </Link>
                        </Button>
                    ) : (
                        <>
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="hidden rounded-full sm:inline-flex"
                            >
                                <Link href={login()}>Log in</Link>
                            </Button>
                            {canRegister && (
                                <Button
                                    asChild
                                    size="sm"
                                    className="rounded-full px-4"
                                >
                                    <Link href={register()}>Start free</Link>
                                </Button>
                            )}
                        </>
                    )}

                    <Sheet>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="md:hidden"
                                aria-label="Open menu"
                            >
                                <ListIcon className="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="top" className="px-6 pt-5 pb-8">
                            <SheetTitle className="sr-only">Menu</SheetTitle>
                            <MarketingWordmark />
                            <nav className="mt-4 flex flex-col">
                                {marketingNavigation.map((item) => (
                                    <Link
                                        key={item.title}
                                        href={item.href}
                                        className="border-b border-border/60 py-3.5 text-base font-medium"
                                    >
                                        {item.title}
                                    </Link>
                                ))}
                                {!auth.user && (
                                    <Link
                                        href={login()}
                                        className="py-3.5 text-base font-medium"
                                    >
                                        Log in
                                    </Link>
                                )}
                            </nav>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </header>
    );
}
