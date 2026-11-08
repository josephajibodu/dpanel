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
    /** Red border and footer, for actions that can't be undone. */
    variant?: 'default' | 'danger';
    /** Lets the body run edge to edge under a divider, for lists of rows. */
    flush?: boolean;
    children?: ReactNode;
    className?: string;
}

/**
 * One block of a settings page: a title and explanation, the controls, and an
 * optional footer strip with a hint on the left and the save button on the right.
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
        <section
            className={cn(
                'overflow-hidden rounded-xl border bg-card text-card-foreground',
                isDanger && 'border-red-200 dark:border-red-900/60',
                className,
            )}
        >
            <div className="flex flex-col gap-4 px-6 pt-5 pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1">
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && (
                        <p className="max-w-xl text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {headerAction && <div className="shrink-0">{headerAction}</div>}
            </div>

            {children && (
                <div className={cn(flush ? 'border-t' : 'px-6 pb-6')}>
                    {children}
                </div>
            )}

            {hasFooter && (
                <div
                    className={cn(
                        'flex flex-col gap-3 border-t bg-muted/40 px-6 py-3 sm:flex-row sm:items-center sm:justify-between',
                        isDanger &&
                            'border-red-200 bg-red-50/60 dark:border-red-900/60 dark:bg-red-950/20',
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
        <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
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
