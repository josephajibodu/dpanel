import { CheckIcon } from 'lucide-react';

import { Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const options: { value: Appearance; label: string; hint: string }[] = [
    { value: 'light', label: 'Light', hint: 'Always light' },
    { value: 'dark', label: 'Dark', hint: 'Always dark' },
    { value: 'system', label: 'System', hint: 'Follows your device' },
];

/** A tiny drawing of the app (sidebar, heading, two cards) in one theme. */
function MiniWindow({ dark }: { dark: boolean }) {
    return (
        <div
            className={cn(
                'flex h-full w-full gap-1.5 p-2',
                dark ? 'bg-neutral-900' : 'bg-neutral-100',
            )}
        >
            <div
                className={cn(
                    'w-1/4 space-y-1 rounded-sm p-1',
                    dark ? 'bg-neutral-800' : 'bg-white',
                )}
            >
                <div className="h-1.5 w-3 rounded-full bg-blue-500" />
                <div
                    className={cn(
                        'h-1 rounded-full',
                        dark ? 'bg-neutral-600' : 'bg-neutral-300',
                    )}
                />
                <div
                    className={cn(
                        'h-1 w-3/4 rounded-full',
                        dark ? 'bg-neutral-600' : 'bg-neutral-300',
                    )}
                />
            </div>
            <div className="flex-1 space-y-1.5">
                <div
                    className={cn(
                        'h-1.5 w-1/2 rounded-full',
                        dark ? 'bg-neutral-500' : 'bg-neutral-400',
                    )}
                />
                <div
                    className={cn(
                        'h-5 rounded-sm',
                        dark ? 'bg-neutral-800' : 'bg-white',
                    )}
                />
                <div
                    className={cn(
                        'h-5 rounded-sm',
                        dark ? 'bg-neutral-800' : 'bg-white',
                    )}
                />
            </div>
        </div>
    );
}

export default function AppearanceTabs() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="radiogroup"
            aria-label="Theme"
            className="grid gap-4 sm:grid-cols-3"
        >
            {options.map(({ value, label, hint }) => {
                const selected = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => updateAppearance(value)}
                        className={cn(
                            'group rounded-xl border p-1.5 text-left transition-colors',
                            selected
                                ? 'border-foreground ring-1 ring-foreground'
                                : 'hover:border-foreground/40',
                        )}
                    >
                        <div className="aspect-[16/10] overflow-hidden rounded-lg border">
                            {value === 'system' ? (
                                <div className="flex h-full">
                                    <div className="w-1/2 overflow-hidden">
                                        <div className="h-full w-[200%]">
                                            <MiniWindow dark={false} />
                                        </div>
                                    </div>
                                    <div className="w-1/2 overflow-hidden">
                                        <div className="-ml-[100%] h-full w-[200%]">
                                            <MiniWindow dark />
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <MiniWindow dark={value === 'dark'} />
                            )}
                        </div>
                        <div className="flex items-center justify-between px-1.5 pt-2.5 pb-1">
                            <div>
                                <p className="text-sm font-medium">{label}</p>
                                <p className="text-xs text-muted-foreground">
                                    {hint}
                                </p>
                            </div>
                            <span
                                className={cn(
                                    'flex size-5 items-center justify-center rounded-full border',
                                    selected &&
                                        'border-foreground bg-foreground text-background',
                                )}
                            >
                                {selected && <CheckIcon className="size-3" />}
                            </span>
                        </div>
                    </button>
                );
            })}
        </div>
    );
}
