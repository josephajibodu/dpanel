import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface SettingsSectionProps {
    title: string;
    description?: ReactNode;
    /** Shown at the top right, beside the title (a status badge or a button). */
    headerAction?: ReactNode;
    /** Muted text on the left of the footer strip. */
    footerHint?: ReactNode;
    /** Buttons on the right of the footer strip. */
    footerAction?: ReactNode;
    /** Red outline and footer, for actions that can't be undone. */
    variant?: 'default' | 'danger';
    /** Lets the body run edge to edge, for lists of rows. */
    flush?: boolean;
    children?: ReactNode;
    className?: string;
}

/**
 * One block of a settings page: a title and explanation above a card that holds
 * the controls, with an optional footer strip for a hint and the save button.
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
    const hasFooter = Boolean(footerHint || footerAction);

    return (
        <section className={cn('space-y-3', className)}>
            <div className="flex flex-col gap-3 px-1 sm:flex-row sm:items-end sm:justify-between">
                <div className="space-y-0.5">
                    <h2 className="text-[15px] font-semibold">{title}</h2>
                    {description && (
                        <p className="max-w-xl text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {headerAction && <div className="shrink-0">{headerAction}</div>}
            </div>

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
 * A row inside a flush section: label and explanation on the left, the control
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
        <div className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0 space-y-0.5">
                <div className="text-sm font-medium">{label}</div>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children && <div className="shrink-0">{children}</div>}
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
