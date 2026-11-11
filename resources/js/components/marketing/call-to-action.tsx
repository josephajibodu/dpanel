import { Link } from '@inertiajs/react';
import { ArrowRightIcon } from '@phosphor-icons/react';

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
        <section className="px-6 py-24 sm:py-32">
            <div className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-neutral-950 px-8 py-20 text-center text-white ring-1 ring-white/10 sm:px-16">
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-[linear-gradient(to_right,rgb(255_255_255/0.06)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.06)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_70%_70%_at_50%_100%,black,transparent)] bg-[size:44px_44px]"
                />
                <div
                    aria-hidden="true"
                    className="absolute -bottom-40 left-1/2 h-80 w-[640px] -translate-x-1/2 rounded-full bg-brand/50 blur-[120px]"
                />
                <div
                    aria-hidden="true"
                    className="absolute -right-24 -bottom-32 h-64 w-[420px] rounded-full bg-brand-accent/35 blur-[120px]"
                />

                <div className="relative">
                    <h2 className="mx-auto max-w-2xl text-3xl font-semibold tracking-[-0.025em] text-balance sm:text-5xl">
                        {title}
                    </h2>
                    <p className="mx-auto mt-5 max-w-lg text-lg text-pretty text-white/65">
                        {description}
                    </p>
                    <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button
                            asChild
                            size="lg"
                            className="h-11 rounded-full bg-white px-6 text-neutral-950 hover:bg-white/90"
                        >
                            <Link href={register()}>
                                Start for free
                                <ArrowRightIcon />
                            </Link>
                        </Button>
                        <Button
                            asChild
                            size="lg"
                            variant="ghost"
                            className="h-11 rounded-full px-6 text-white hover:bg-white/10 hover:text-white"
                        >
                            <Link href={contact()}>Talk to us</Link>
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}
