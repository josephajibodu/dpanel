import * as React from 'react';

import { cn } from '@/lib/utils';

interface TableProps extends React.HTMLAttributes<HTMLTableElement> {
    /**
     * Leaves off the card around the table and keeps the rounded header, for
     * a table that already sits inside a card.
     */
    plain?: boolean;
}

/**
 * A table drawn as a card: a grey header strip across the top and a line
 * between rows.
 */
const Table = React.forwardRef<HTMLTableElement, TableProps>(
    ({ className, plain = false, ...props }, ref) => (
        <div
            data-plain={plain || undefined}
            className={cn(
                'group/table relative w-full overflow-auto',
                !plain && 'rounded-2xl bg-card shadow-card',
            )}
        >
            <table
                ref={ref}
                className={cn(
                    'w-full border-separate border-spacing-0 caption-bottom text-[13px]',
                    className,
                )}
                {...props}
            />
        </div>
    ),
);
Table.displayName = 'Table';

const TableHeader = React.forwardRef<
    HTMLTableSectionElement,
    React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
    <thead ref={ref} className={cn(className)} {...props} />
));
TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef<
    HTMLTableSectionElement,
    React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
    <tbody ref={ref} className={cn(className)} {...props} />
));
TableBody.displayName = 'TableBody';

const TableFooter = React.forwardRef<
    HTMLTableSectionElement,
    React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
    <tfoot
        ref={ref}
        className={cn(
            'border-t bg-muted/50 font-medium [&>tr]:last:border-b-0',
            className,
        )}
        {...props}
    />
));
TableFooter.displayName = 'TableFooter';

const TableRow = React.forwardRef<
    HTMLTableRowElement,
    React.HTMLAttributes<HTMLTableRowElement>
>(({ className, ...props }, ref) => (
    <tr
        ref={ref}
        className={cn('group', className)}
        {...props}
    />
));
TableRow.displayName = 'TableRow';

const TableHead = React.forwardRef<
    HTMLTableCellElement,
    React.ThHTMLAttributes<HTMLTableCellElement>
>(({ className, ...props }, ref) => (
    <th
        ref={ref}
        className={cn(
            'bg-muted text-muted-foreground border-border h-9 border-b px-4 text-left align-middle text-xs font-medium whitespace-nowrap group-data-[plain]/table:border-b-0 group-data-[plain]/table:text-[13px] group-data-[plain]/table:first:rounded-l-lg group-data-[plain]/table:last:rounded-r-lg [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[0.125rem]',
            className,
        )}
        {...props}
    />
));
TableHead.displayName = 'TableHead';

const TableCell = React.forwardRef<
    HTMLTableCellElement,
    React.TdHTMLAttributes<HTMLTableCellElement>
>(({ className, ...props }, ref) => (
    <td
        ref={ref}
        className={cn(
            'border-border group-hover:bg-muted/60 border-b px-4 py-2.5 align-middle transition-colors group-last:border-b-0 [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[0.125rem]',
            className,
        )}
        {...props}
    />
));
TableCell.displayName = 'TableCell';

const TableCaption = React.forwardRef<
    HTMLTableCaptionElement,
    React.HTMLAttributes<HTMLTableCaptionElement>
>(({ className, ...props }, ref) => (
    <caption
        ref={ref}
        className={cn('text-muted-foreground mt-4 text-sm', className)}
        {...props}
    />
));
TableCaption.displayName = 'TableCaption';

export {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
};
