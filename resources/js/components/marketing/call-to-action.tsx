import { Link } from '@inertiajs/react';

import Scene from '@/components/marketing/scene';
import { Button } from '@/components/ui/button';
import { contact, register } from '@/routes';

/**
 * The closing invitation at the bottom of public pages.
 */
export default function CallToAction({
    title = 'Your servers. Deployed like a platform.',
    description = 'Connect a cloud account and ship your first site in the next ten minutes. No card required.',
}: {
    title?: string;
    description?: string;
}) {
    return (
        <Scene
            tone="brand"
            className="mt-24 px-6 py-20 text-white sm:mt-32 sm:py-28 lg:px-8"
        >
            <div className="mx-auto flex max-w-7xl flex-col gap-10 lg:flex-row lg:items-end lg:justify-between">
                <div className="max-w-2xl">
                    <h2 className="text-4xl leading-[1.05] font-medium tracking-[-0.03em] text-balance sm:text-6xl">
                        {title}
                    </h2>
                    <p className="mt-6 max-w-lg text-lg leading-relaxed text-pretty text-white/80">
                        {description}
                    </p>
                </div>
                <div className="flex flex-col gap-3 sm:flex-row">
                    <Button
                        asChild
                        size="lg"
                        className="h-12 bg-white bg-none px-7 text-base text-neutral-950 shadow-none hover:bg-white/90"
                    >
                        <Link href={register()}>Start for free</Link>
                    </Button>
                    <Button
                        asChild
                        size="lg"
                        variant="ghost"
                        className="h-12 px-7 text-base text-white ring-1 ring-white/40 ring-inset hover:bg-white/10 hover:text-white"
                    >
                        <Link href={contact()}>Talk to us</Link>
                    </Button>
                </div>
            </div>
        </Scene>
    );
}
