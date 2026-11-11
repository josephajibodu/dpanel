import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface SettingsSectionProps {
    /** Leave out on a page with one section, where the page title already says it. */
    title?: string;
    description?: ReactNode;
    /** Shown at the top right, beside the title (a status badge or a button). */
    headerAction?: ReactNode;
    /** Muted text on the left of the footer strip. */
    footerHint?: ReactNode;
    /** Buttons on the right of the footer strip. */
    footerAction?: ReactNode;
    /** Red outline and footer, for actions that can't be undone. */
    variant?: 'default' | 'danger';
    /** Drops the body's padding, for a list of rows that brings its own. */
    flush?: boolean;
    children?: ReactNode;
    className?: string;
}

/**
 * One block of a settings page: a title and explanation above a card that holds
 * the controls, with an optional footer strip for a hint and the save button.
 * The title lines up with the card's left edge.
 */
export function SettingsSection({
    title,
    description,
    headerAction,
    footerHint,
    footerAction,
    variant = 'default',
    flush = false,
    children,
    className,
}: SettingsSectionProps) {
    const isDanger = variant === 'danger';
    const hasHeader = Boolean(title || description || headerAction);
    const hasFooter = Boolean(footerHint || footerAction);

    return (
        <section className={cn('space-y-3', className)}>
            {hasHeader && (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-0.5">
                        {title && (
                            <h2 className="text-base font-semibold">{title}</h2>
                        )}
                        {description && (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {headerAction && (
                        <div className="shrink-0">{headerAction}</div>
                    )}
                </div>
            )}

            {(children || hasFooter) && (
                <div
                    className={cn(
                        'overflow-hidden rounded-2xl bg-card text-card-foreground shadow-card',
                        isDanger && 'ring-1 ring-destructive/30',
                    )}
                >
                    {children && (
                        <div className={cn(!flush && 'p-5')}>{children}</div>
                    )}

                    {hasFooter && (
                        <div
                            className={cn(
                                'flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between',
                                children && 'border-t bg-muted/60',
                                children &&
                                    isDanger &&
                                    'border-destructive/20 bg-destructive/[0.05]',
                            )}
                        >
                            <div className="text-sm text-muted-foreground">
                                {footerHint}
                            </div>
                            {footerAction && (
                                <div className="flex shrink-0 items-center gap-3">
                                    {footerAction}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * The rows of a flush section. The lines between them stop short of the card's
 * edges.
 */
export function SettingsRows({ children }: { children: ReactNode }) {
    return <div className="divide-y px-5">{children}</div>;
}

/**
 * One row inside `SettingsRows`: label and explanation on the left, the control
 * on the right.
 */
export function SettingsRow({
    label,
    description,
    children,
}: {
    label: ReactNode;
    description?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0 space-y-0.5">
                <div className="text-sm font-medium">{label}</div>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children && (
                <div className="flex shrink-0 items-center gap-3">
                    {children}
                </div>
            )}
        </div>
    );
}

/** The fading "Saved" note that sits beside a save button. */
export function SavedNote({ show }: { show: boolean }) {
    return (
        <span
            className={cn(
                'text-sm text-muted-foreground transition-opacity',
                show ? 'opacity-100' : 'opacity-0',
            )}
            aria-live="polite"
        >
            {show ? 'Saved' : ''}
        </span>
    );
}
