import { Head, Link, usePage } from '@inertiajs/react';
import {
    GitBranchIcon,
    KeyIcon,
    LockIcon,
    RadioIcon,
    ServerIcon,
    TerminalIcon,
} from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { type SharedData } from '@/types';

const features = [
    {
        icon: ServerIcon,
        title: 'Server provisioning',
        description:
            'Spin up servers on DigitalOcean, Hetzner, or Vultr in a couple of clicks.',
    },
    {
        icon: TerminalIcon,
        title: 'One-click stacks',
        description:
            'Nginx, PHP, MySQL or Postgres, Redis, and Node installed and configured for you.',
    },
    {
        icon: GitBranchIcon,
        title: 'Zero-downtime deploys',
        description:
            'Git-based deployments with atomic releases and one-click rollback.',
    },
    {
        icon: LockIcon,
        title: 'SSL & domains',
        description:
            "Automatic Let's Encrypt certificates, renewed before they expire — with alerts if one ever needs attention.",
    },
    {
        icon: KeyIcon,
        title: 'SSH key management',
        description:
            'Sync and revoke access across your entire fleet from one place.',
    },
    {
        icon: RadioIcon,
        title: 'Realtime deployment logs',
        description:
            'Watch every deployment stream live, no need to SSH in and tail a log file.',
    },
];

const steps = [
    {
        number: '01',
        title: 'Connect a provider',
        description:
            'Link your DigitalOcean, Hetzner, or Vultr account with an API token.',
    },
    {
        number: '02',
        title: 'Create a site',
        description:
            'Point at a Git repository, pick a stack, and FlitOps provisions the rest.',
    },
    {
        number: '03',
        title: 'Push to deploy',
        description:
            'Every push kicks off a zero-downtime deployment, live in seconds.',
    },
];

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth, currentTeam } = usePage<SharedData>().props;
    const dashboardHref = currentTeam
        ? `/${currentTeam.slug}/dashboard`
        : '/dashboard';

    return (
        <>
            <Head title="FlitOps — Deploy and manage your own servers" />

            <div className="min-h-screen bg-background text-foreground">
                <header className="sticky top-0 z-50 border-b border-border/60 bg-background/80 backdrop-blur-sm">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                        <Link href="/" className="flex items-center gap-2">
                            <div className="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
                                <AppLogoIcon className="size-4 fill-current" />
                            </div>
                            <span className="font-semibold tracking-tight">
                                FlitOps
                            </span>
                        </Link>

                        <nav className="hidden items-center gap-8 text-sm font-medium md:flex">
                            <a
                                href="#features"
                                className="text-muted-foreground transition-colors hover:text-foreground"
                            >
                                Features
                            </a>
                            <a
                                href="#how-it-works"
                                className="text-muted-foreground transition-colors hover:text-foreground"
                            >
                                How it works
                            </a>
                        </nav>

                        <div className="flex items-center gap-2">
                            <AppearanceToggleDropdown />
                            {auth.user ? (
                                <Button asChild size="sm">
                                    <Link href={dashboardHref}>Dashboard</Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    {canRegister && (
                                        <Button asChild size="sm">
                                            <Link href={register()}>
                                                Get started
                                            </Link>
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </header>

                <main>
                    <section className="mx-auto max-w-6xl px-6 pt-20 pb-24 text-center sm:pt-28">
                        <div className="mx-auto mb-6 inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-xs font-medium text-muted-foreground">
                            <span className="inline-block size-1.5 rounded-full bg-chart-2" />
                            DigitalOcean · Hetzner · Vultr
                        </div>

                        <h1 className="mx-auto max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-6xl">
                            Deploy and manage your own servers, without the ops
                            team.
                        </h1>

                        <p className="mx-auto mt-6 max-w-xl text-lg text-balance text-muted-foreground">
                            FlitOps provisions servers, installs your stack, and
                            ships every git push as a zero-downtime deployment —
                            so you own the infrastructure without babysitting
                            it.
                        </p>

                        <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <Button asChild size="lg">
                                <Link href={canRegister ? register() : login()}>
                                    Get started
                                </Link>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <a href="#how-it-works">See how it works</a>
                            </Button>
                        </div>

                        <div className="mx-auto mt-16 max-w-2xl overflow-hidden rounded-xl border border-border bg-card text-left">
                            <div className="flex items-center gap-1.5 border-b border-border px-4 py-3">
                                <span className="size-2.5 rounded-full bg-red-400" />
                                <span className="size-2.5 rounded-full bg-yellow-400" />
                                <span className="size-2.5 rounded-full bg-green-400" />
                            </div>
                            <div className="space-y-1.5 px-5 py-5 font-log text-sm">
                                <p className="text-muted-foreground">
                                    $ git push origin main
                                </p>
                                <p className="text-muted-foreground">
                                    Deploying app.flitops.xyz…
                                </p>
                                <p className="text-muted-foreground">
                                    Installing dependencies…
                                </p>
                                <p className="text-muted-foreground">
                                    Running release hooks…
                                </p>
                                <p className="text-chart-2">
                                    ✓ Deployed to app.flitops.xyz in 8.2s
                                </p>
                            </div>
                        </div>
                    </section>

                    <section
                        id="features"
                        className="border-t border-border px-6 py-24"
                    >
                        <div className="mx-auto max-w-6xl">
                            <div className="mx-auto max-w-xl text-center">
                                <h2 className="text-3xl font-semibold tracking-tight">
                                    Everything you need to self-host
                                </h2>
                                <p className="mt-3 text-muted-foreground">
                                    The parts of running infrastructure that are
                                    tedious, automated away.
                                </p>
                            </div>

                            <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {features.map((feature) => (
                                    <div
                                        key={feature.title}
                                        className="rounded-xl border border-border bg-card p-6"
                                    >
                                        <div className="mb-4 flex size-10 items-center justify-center rounded-lg bg-muted">
                                            <feature.icon className="size-5 text-foreground" />
                                        </div>
                                        <h3 className="font-semibold">
                                            {feature.title}
                                        </h3>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {feature.description}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section
                        id="how-it-works"
                        className="border-t border-border px-6 py-24"
                    >
                        <div className="mx-auto max-w-6xl">
                            <div className="mx-auto max-w-xl text-center">
                                <h2 className="text-3xl font-semibold tracking-tight">
                                    From zero to deployed
                                </h2>
                                <p className="mt-3 text-muted-foreground">
                                    Three steps, no runbooks required.
                                </p>
                            </div>

                            <div className="mt-14 grid gap-10 sm:grid-cols-3">
                                {steps.map((step) => (
                                    <div key={step.number}>
                                        <span className="text-4xl font-semibold text-muted-foreground/40">
                                            {step.number}
                                        </span>
                                        <h3 className="mt-3 font-semibold">
                                            {step.title}
                                        </h3>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {step.description}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="border-t border-border px-6 py-24">
                        <div className="mx-auto flex max-w-6xl flex-col items-center gap-6 rounded-2xl bg-foreground px-8 py-16 text-center text-background">
                            <h2 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                                Ready to run your own infrastructure?
                            </h2>
                            <p className="max-w-md opacity-80">
                                Create your first server and deploy your first
                                site in minutes.
                            </p>
                            <Button asChild size="lg" variant="secondary">
                                <Link href={canRegister ? register() : login()}>
                                    Get started
                                </Link>
                            </Button>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-border px-6 py-10">
                    <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 text-sm text-muted-foreground sm:flex-row">
                        <div className="flex items-center gap-2">
                            <AppLogoIcon className="size-4 fill-foreground" />
                            <span>FlitOps</span>
                        </div>
                        <p>
                            &copy; {new Date().getFullYear()} FlitOps. All
                            rights reserved.
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
