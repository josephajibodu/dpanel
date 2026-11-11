import { Link, router, usePage } from '@inertiajs/react';
import { SignOutIcon } from '@phosphor-icons/react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/hooks/use-initials';
import { logout } from '@/routes';
import { type SharedData } from '@/types';

/**
 * Who is signed in, with a button to log out. It is not a menu: teams are
 * switched from the top of the sidebar, and settings has its own link.
 */
export function NavUser() {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();

    return (
        <div className="flex items-center gap-2 px-1.5 pt-2 pb-0.5 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
            <div className="flex min-w-0 flex-1 items-center gap-2 group-data-[collapsible=icon]:hidden">
                <Avatar className="size-7 overflow-hidden rounded-full">
                    <AvatarImage src={auth.user.avatar} alt={auth.user.name} />
                    <AvatarFallback className="bg-neutral-700 text-[11px] font-semibold text-white">
                        {getInitials(auth.user.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="grid min-w-0 leading-tight">
                    <span className="truncate text-sm font-medium text-sidebar-accent-foreground">
                        {auth.user.name}
                    </span>
                    <span className="truncate text-xs text-sidebar-foreground/60">
                        {auth.user.email}
                    </span>
                </div>
            </div>

            <Tooltip>
                <TooltipTrigger asChild>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        aria-label="Log out"
                        data-test="logout-button"
                        className="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[10px] text-sidebar-foreground/80 outline-hidden transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                    >
                        <SignOutIcon className="size-[18px]" />
                    </Link>
                </TooltipTrigger>
                <TooltipContent side="right">Log out</TooltipContent>
            </Tooltip>
        </div>
    );
}
