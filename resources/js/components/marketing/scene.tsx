import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

const tones = {
    brand: 'bg-brand [--scene-line:rgb(255_255_255/0.13)]',
    blue: 'bg-blue-100 [--scene-line:rgb(255_255_255/0.6)] dark:bg-blue-950/70 dark:[--scene-line:rgb(255_255_255/0.05)]',
    green: 'bg-emerald-100 [--scene-line:rgb(255_255_255/0.6)] dark:bg-emerald-950/70 dark:[--scene-line:rgb(255_255_255/0.05)]',
    amber: 'bg-amber-100 [--scene-line:rgb(255_255_255/0.6)] dark:bg-amber-950/60 dark:[--scene-line:rgb(255_255_255/0.05)]',
    violet: 'bg-violet-100 [--scene-line:rgb(255_255_255/0.6)] dark:bg-violet-950/70 dark:[--scene-line:rgb(255_255_255/0.05)]',
};

export type SceneTone = keyof typeof tones;

/**
 * A flat coloured panel with a fine grid drawn on it, used as the backdrop
 * for product visuals on the public site.
 */
export default function Scene({
    tone,
    className,
    children,
}: {
    tone: SceneTone;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'relative isolate overflow-hidden',
                tones[tone],
                className,
            )}
        >
            <div
                aria-hidden="true"
                className="absolute inset-0 -z-10 bg-[linear-gradient(to_right,var(--scene-line)_1px,transparent_1px),linear-gradient(to_bottom,var(--scene-line)_1px,transparent_1px)] bg-[size:40px_40px] bg-center"
            />
            {children}
        </div>
    );
}
