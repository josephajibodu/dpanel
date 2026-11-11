import { Link } from '@inertiajs/react';

import Scene from '@/components/marketing/scene';
import { Button } from '@/components/ui/button';
import { contact, register } from '@/routes';

const steps = ['Connect your cloud', 'Provision a server', 'Push to deploy'];

/**
 * The closing invitation at the bottom of public pages: a headline, the two
 * main actions, and the three steps it takes to get a first site live.
 */
export default function CallToAction() {
    return (
        <Scene
            tone="brand"
            className="mt-24 rounded-t-[2rem] px-6 py-20 text-white sm:mt-32 sm:rounded-t-[2.5rem] sm:py-28 lg:px-8"
        >
            <div className="mx-auto grid max-w-7xl gap-14 lg:grid-cols-2 lg:items-end lg:gap-20">
                <div>
                    <h2 className="text-4xl leading-[1.05] font-normal tracking-[-0.03em] text-balance sm:text-6xl">
                        Your servers.{' '}
                        <span className="text-white/65">
                            Deployed like a platform.
                        </span>
                    </h2>
                    <p className="mt-6 max-w-lg text-lg leading-relaxed text-pretty text-white/80">
                        Connect a cloud account and ship your first site in the
                        next ten minutes. No card required.
                    </p>
                    <div className="mt-10 flex flex-col gap-3 sm:flex-row">
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

                <ol className="border-t border-white/25">
                    {steps.map((step, index) => (
                        <li
                            key={step}
                            className="flex items-baseline gap-6 border-b border-white/25 py-5 text-2xl tracking-tight sm:text-3xl"
                        >
                            <span className="font-log text-sm tracking-normal text-white/60">
                                {String(index + 1).padStart(2, '0')}
                            </span>
                            {step}
                        </li>
                    ))}
                </ol>
            </div>
        </Scene>
    );
}
