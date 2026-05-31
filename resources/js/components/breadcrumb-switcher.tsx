import { Link, router } from '@inertiajs/react';
import { Check, ChevronsUpDown } from 'lucide-react';

import { SwitcherIcon } from '@/components/switcher-icon';
import {
    BreadcrumbItem,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export interface BreadcrumbSwitcherItem {
    id: number;
    label: string;
}

interface BreadcrumbSwitcherProps {
    label: string;
    href: string;
    isLast: boolean;
    menuLabel: string;
    items: BreadcrumbSwitcherItem[];
    currentId: number;
    onSelect: (item: BreadcrumbSwitcherItem) => void;
}

function SwitcherLabel({
    label,
    href,
    isLast,
}: {
    label: string;
    href: string;
    isLast: boolean;
}) {
    const content = (
        <>
            <SwitcherIcon label={label} />
            <span className="max-w-[12rem] truncate">{label}</span>
        </>
    );

    if (isLast) {
        return (
            <span className="text-foreground inline-flex min-w-0 items-center gap-2 px-2 py-1">
                {content}
            </span>
        );
    }

    return (
        <Link
            href={href}
            className="text-muted-foreground hover:text-foreground inline-flex min-w-0 items-center gap-2 px-2 py-1 transition-colors"
        >
            {content}
        </Link>
    );
}

export function BreadcrumbSwitcher({
    label,
    href,
    isLast,
    menuLabel,
    items,
    currentId,
    onSelect,
}: BreadcrumbSwitcherProps) {
    return (
        <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
                <div className="border-border/60 bg-muted/20 inline-flex items-stretch overflow-hidden rounded-md border">
                    <SwitcherLabel label={label} href={href} isLast={isLast} />
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            className={cn(
                                'text-muted-foreground hover:text-foreground border-border/60 inline-flex w-7 shrink-0 cursor-pointer items-center justify-center border-l outline-none',
                                'hover:bg-muted/40 focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-1',
                            )}
                            aria-label={menuLabel}
                        >
                            <ChevronsUpDown className="size-3 opacity-60" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="min-w-52">
                            <DropdownMenuLabel className="text-muted-foreground text-xs">
                                {menuLabel}
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {items.map((item) => (
                                <DropdownMenuItem
                                    key={item.id}
                                    onClick={() => onSelect(item)}
                                    className="cursor-pointer gap-2"
                                >
                                    <SwitcherIcon label={item.label} />
                                    <span className="truncate">{item.label}</span>
                                    {item.id === currentId && (
                                        <Check className="ml-auto size-3.5 shrink-0" />
                                    )}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </BreadcrumbItem>
        </>
    );
}

interface BreadcrumbSwitcherWrapperProps {
    breadcrumb: { title: string; href: string };
    isLast: boolean;
    resolveCurrent: () => { id: number; label: string } | null;
    menuLabel: string;
    items: BreadcrumbSwitcherItem[];
    onSelect: (item: BreadcrumbSwitcherItem) => void;
}

export function BreadcrumbSwitcherWrapper({
    breadcrumb,
    isLast,
    resolveCurrent,
    menuLabel,
    items,
    onSelect,
}: BreadcrumbSwitcherWrapperProps) {
    const current = resolveCurrent();

    if (!current || items.length === 0) {
        return (
            <>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    {isLast ? (
                        <BreadcrumbPage>{breadcrumb.title}</BreadcrumbPage>
                    ) : (
                        <Link
                            href={breadcrumb.href}
                            className="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            {breadcrumb.title}
                        </Link>
                    )}
                </BreadcrumbItem>
            </>
        );
    }

    return (
        <BreadcrumbSwitcher
            label={current.label}
            href={breadcrumb.href}
            isLast={isLast}
            menuLabel={menuLabel}
            items={items}
            currentId={current.id}
            onSelect={onSelect}
        />
    );
}

export function switchToItem(href: string, item: BreadcrumbSwitcherItem, currentId: number): void {
    if (item.id === currentId) {
        return;
    }

    router.visit(href);
}
