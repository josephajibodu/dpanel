import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

export interface PaginationMeta {
    current_page: number;
    from: number | null;
    to: number | null;
    total: number;
    last_page: number;
    per_page?: number;
}

/** Laravel pagination link entry (when links is an array). */
interface PaginationLinkItem {
    url: string | null;
    label: string;
    active?: boolean;
}

/** Laravel pagination links (object style, e.g. from some responses). */
interface PaginationLinksObject {
    prev_page_url?: string | null;
    next_page_url?: string | null;
}

/**
 * Normalize Laravel pagination links to prev/next URLs.
 * Handles both array format (e.g. [{ url, label, active }, ...]) and object format (prev_page_url, next_page_url).
 */
export function getPaginationUrls(links: unknown): {
    prevUrl: string | null;
    nextUrl: string | null;
} {
    if (links == null) {
        return { prevUrl: null, nextUrl: null };
    }
    if (Array.isArray(links)) {
        const prev =
            (links as PaginationLinkItem[]).find((l) =>
                /previous|&laquo;/i.test(String(l.label)),
            )?.url ?? null;
        const next =
            (links as PaginationLinkItem[]).find((l) =>
                /next|&raquo;/i.test(String(l.label)),
            )?.url ?? null;
        return { prevUrl: prev ?? null, nextUrl: next ?? null };
    }
    if (typeof links === 'object' && !Array.isArray(links) && ('prev_page_url' in links || 'next_page_url' in links)) {
        const o = links as PaginationLinksObject;
        return {
            prevUrl: o.prev_page_url ?? null,
            nextUrl: o.next_page_url ?? null,
        };
    }
    return { prevUrl: null, nextUrl: null };
}

interface PaginationProps {
    /** Meta from Laravel paginator (for "Showing X to Y of Z" and "Page N of M") */
    meta?: PaginationMeta | null;
    /** Previous page URL. When null, Previous button is disabled. */
    prevUrl?: string | null;
    /** Next page URL. When null, Next button is disabled. */
    nextUrl?: string | null;
    /** Optional override for the results label, e.g. "results" or "servers" */
    resultsLabel?: string;
    className?: string;
}

export function Pagination({
    meta,
    prevUrl,
    nextUrl,
    resultsLabel = 'results',
    className,
}: PaginationProps) {
    const showPagination = meta || prevUrl || nextUrl;
    if (!showPagination) {
        return null;
    }

    const resultsText = meta
        ? `Showing ${meta.from ?? 0} to ${meta.to ?? 0} of ${meta.total} ${resultsLabel}`
        : null;

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-between gap-4 border-t px-4 py-3 sm:flex-row',
                className,
            )}
        >
            {resultsText && (
                <p className="text-muted-foreground text-sm">{resultsText}</p>
            )}
            <div className="flex items-center gap-2">
                {prevUrl ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={prevUrl}>
                            <ChevronLeftIcon className="h-4 w-4" />
                            Previous
                        </Link>
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" disabled>
                        <ChevronLeftIcon className="h-4 w-4" />
                        Previous
                    </Button>
                )}
                {meta && meta.last_page > 1 && (
                    <span className="text-muted-foreground px-2 text-sm">
                        Page {meta.current_page} of {meta.last_page}
                    </span>
                )}
                {nextUrl ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={nextUrl}>
                            Next
                            <ChevronRightIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" disabled>
                        Next
                        <ChevronRightIcon className="h-4 w-4" />
                    </Button>
                )}
            </div>
        </div>
    );
}
