import { Link } from '@inertiajs/react';
import { CircleNotchIcon } from '@phosphor-icons/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/*
 * The building blocks shared by the "create" pages (servers, sites): one panel
 * of form sections beside a sticky summary that holds the submit button.
 */

interface SegmentedControlProps<T extends string> {
    label: string;
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
}

/** A small two-or-more-way switch, styled like the appearance tabs in settings. */
export function SegmentedControl<T extends string>({
    label,
    value,
    onChange,
    options,
}: SegmentedControlProps<T>) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="inline-flex gap-1 rounded-lg bg-muted p-1"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded-md px-3 py-1 text-sm transition-colors',
                        value === option.value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

interface FormSectionProps {
    title: string;
    description?: string;
    /** Shown at the right of the title, e.g. a switch that turns the section on. */
    action?: React.ReactNode;
    children?: React.ReactNode;
}

/** One block of the form, separated from the next by a thin line. */
export function FormSection({
    title,
    description,
    action,
    children,
}: FormSectionProps) {
    return (
        <section className="space-y-4 p-5">
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-0.5">
                    <h2 className="text-sm font-medium">{title}</h2>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

/** The small circle used by every radio-style choice on these pages. */
export function RadioDot({ selected }: { selected: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-4 shrink-0 items-center justify-center rounded-full border',
                selected && 'border-primary',
            )}
        >
            {selected && <span className="size-2 rounded-full bg-primary" />}
        </span>
    );
}

interface RadioOptionProps {
    selected: boolean;
    onSelect: () => void;
    title: string;
    description: string;
}

/** A compact, clickable option that behaves like a radio button. */
export function RadioOption({
    selected,
    onSelect,
    title,
    description,
}: RadioOptionProps) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'flex items-start gap-3 rounded-md border px-3 py-2.5 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                selected ? 'border-primary bg-muted/50' : 'hover:bg-muted/40',
            )}
        >
            <span className="mt-0.5">
                <RadioDot selected={selected} />
            </span>
            <span className="min-w-0">
                <span className="block truncate font-medium">{title}</span>
                <span className="block text-xs text-muted-foreground">
                    {description}
                </span>
            </span>
        </button>
    );
}

interface SummaryCardProps {
    rows: { label: string; value: string | null }[];
    /** Extra content between the rows and the buttons, e.g. a cost estimate. */
    children?: React.ReactNode;
    submitLabel: string;
    processing: boolean;
    disabled: boolean;
    cancelHref: string;
}

/** A sticky recap of the choices so far, holding the submit button. */
export function SummaryCard({
    rows,
    children,
    submitLabel,
    processing,
    disabled,
    cancelHref,
}: SummaryCardProps) {
    return (
        <Card className="py-0 lg:sticky lg:top-4">
            <CardContent className="space-y-4 p-5">
                <dl className="space-y-2 text-sm">
                    {rows.map((row) => (
                        <div
                            key={row.label}
                            className="flex justify-between gap-4"
                        >
                            <dt className="shrink-0 text-muted-foreground">
                                {row.label}
                            </dt>
                            <dd
                                className={cn(
                                    'truncate text-right',
                                    !row.value && 'text-muted-foreground',
                                )}
                            >
                                {row.value ?? '—'}
                            </dd>
                        </div>
                    ))}
                </dl>

                {children}

                <div className="flex flex-col gap-2">
                    <Button type="submit" disabled={processing || disabled}>
                        {processing && (
                            <CircleNotchIcon className="h-4 w-4 animate-spin" />
                        )}
                        {submitLabel}
                    </Button>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={cancelHref}>Cancel</Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
