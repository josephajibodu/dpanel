import { CheckIcon, GitCommitIcon } from '@phosphor-icons/react';

import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

const sidebarItems = [
    'Overview',
    'Deployments',
    'Domains',
    'Environment',
    'Processes',
    'Database',
    'Logs',
];

const deployLines = [
    { text: 'Cloning acme/storefront@a41f9c2 (main)', tone: 'muted' },
    { text: 'Creating release 20261107-142231', tone: 'muted' },
    {
        text: 'composer install --no-dev --optimize-autoloader',
        tone: 'command',
    },
    { text: 'Installed 112 packages in 6.1s', tone: 'muted' },
    { text: 'npm ci && npm run build', tone: 'command' },
    { text: 'Built 48 modules in 3.4s', tone: 'muted' },
    { text: 'php artisan migrate --force', tone: 'command' },
    { text: 'Switching current → release 20261107-142231', tone: 'muted' },
    { text: 'Reloading php8.4-fpm', tone: 'muted' },
    { text: 'Deployed in 14.2s, zero requests dropped', tone: 'success' },
] as const;

const recentDeployments = [
    {
        commit: 'a41f9c2',
        message: 'Add order export to CSV',
        author: 'Ada',
        time: 'now',
        live: true,
    },
    {
        commit: '7be03d1',
        message: 'Cache product listing queries',
        author: 'Kofi',
        time: '2h',
        live: false,
    },
    {
        commit: 'e19c55a',
        message: 'Upgrade to Laravel 13',
        author: 'Ada',
        time: '1d',
        live: false,
    },
];

/**
 * A crafted, static rendition of the flitops dashboard used in the home page hero.
 */
export default function ProductPreview() {
    return (
        <div className="relative mx-auto max-w-5xl">
            <div
                aria-hidden="true"
                className="absolute -inset-x-10 -top-10 bottom-0 -z-10 rounded-[40px] bg-gradient-to-b from-brand/20 via-brand/5 to-transparent blur-2xl"
            />

            <div
                role="img"
                aria-label="The flitops dashboard showing a site mid-deployment"
                className="overflow-hidden rounded-2xl border border-border bg-card shadow-[0_40px_120px_-30px_rgb(0_0_0/0.35)] ring-1 ring-black/5 dark:shadow-[0_40px_120px_-30px_rgb(37_99_235/0.35)] dark:ring-white/5"
            >
                <div className="flex h-10 items-center gap-2 border-b border-border bg-muted/40 px-4">
                    <span className="size-2.5 rounded-full bg-foreground/15" />
                    <span className="size-2.5 rounded-full bg-foreground/15" />
                    <span className="size-2.5 rounded-full bg-foreground/15" />
                    <div className="mx-auto flex h-6 w-64 items-center justify-center rounded-md bg-background/80 text-[11px] text-muted-foreground ring-1 ring-border">
                        flitops.com/acme/servers/fra-1/sites
                    </div>
                </div>

                <div className="grid min-h-[420px] grid-cols-1 md:grid-cols-[200px_1fr]">
                    <aside className="hidden border-r border-border bg-muted/20 p-3 md:block">
                        <div className="flex items-center gap-2 rounded-lg px-2 py-1.5">
                            <span className="flex size-6 items-center justify-center rounded-md bg-brand text-white">
                                <AppLogoIcon className="size-3.5 fill-current" />
                            </span>
                            <span className="text-xs font-semibold">
                                Acme Inc.
                            </span>
                        </div>
                        <div className="mt-4 px-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                            storefront.acme.com
                        </div>
                        <ul className="mt-2 space-y-0.5 text-xs">
                            {sidebarItems.map((item, index) => (
                                <li
                                    key={item}
                                    className={cn(
                                        'rounded-md px-2 py-1.5',
                                        index === 1
                                            ? 'bg-background font-medium text-foreground shadow-xs ring-1 ring-border'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </aside>

                    <div className="flex flex-col gap-4 p-4 text-left sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold">
                                    storefront.acme.com
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Laravel · PHP 8.4 · fra-1 (Hetzner,
                                    Falkenstein)
                                </p>
                            </div>
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-2.5 py-1 text-[11px] font-medium text-amber-700 ring-1 ring-amber-500/20 dark:text-amber-400">
                                <span className="relative flex size-1.5">
                                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-amber-500 opacity-60" />
                                    <span className="relative inline-flex size-1.5 rounded-full bg-amber-500" />
                                </span>
                                Deploying
                            </span>
                        </div>

                        <div className="overflow-hidden rounded-xl bg-neutral-950 ring-1 ring-white/10">
                            <div className="flex items-center justify-between border-b border-white/10 px-4 py-2.5">
                                <span className="flex items-center gap-2 font-log text-[11px] text-white/60">
                                    <GitCommitIcon className="size-3.5" />
                                    a41f9c2 · Add order export to CSV
                                </span>
                                <span className="font-log text-[11px] text-white/40">
                                    live
                                </span>
                            </div>
                            <ol className="space-y-1 px-4 py-3.5 font-log text-[11.5px] leading-relaxed">
                                {deployLines.map((line, index) => (
                                    <li
                                        key={line.text}
                                        style={{
                                            animationDelay: `${300 + index * 260}ms`,
                                        }}
                                        className={cn(
                                            'flex animate-in gap-3 duration-500 fill-mode-both fade-in slide-in-from-bottom-1 motion-reduce:animate-none',
                                            line.tone === 'muted' &&
                                                'text-white/45',
                                            line.tone === 'command' &&
                                                'text-white/85',
                                            line.tone === 'success' &&
                                                'text-emerald-400',
                                        )}
                                    >
                                        <span className="w-4 shrink-0 text-right text-white/20 select-none">
                                            {index + 1}
                                        </span>
                                        <span className="truncate">
                                            {line.tone === 'command' && (
                                                <span className="text-blue-400">
                                                    ${' '}
                                                </span>
                                            )}
                                            {line.tone === 'success' && '✓ '}
                                            {line.text}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </div>

                        <ul className="divide-y divide-border rounded-xl ring-1 ring-border">
                            {recentDeployments.map((deployment) => (
                                <li
                                    key={deployment.commit}
                                    className="flex items-center gap-3 px-4 py-2.5 text-xs"
                                >
                                    <span
                                        className={cn(
                                            'flex size-4 items-center justify-center rounded-full',
                                            deployment.live
                                                ? 'bg-amber-500/15 text-amber-600'
                                                : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
                                        )}
                                    >
                                        {deployment.live ? (
                                            <span className="size-1.5 rounded-full bg-amber-500" />
                                        ) : (
                                            <CheckIcon
                                                className="size-2.5"
                                                strokeWidth={3}
                                            />
                                        )}
                                    </span>
                                    <span className="font-log text-muted-foreground">
                                        {deployment.commit}
                                    </span>
                                    <span className="truncate font-medium">
                                        {deployment.message}
                                    </span>
                                    <span className="ml-auto shrink-0 text-muted-foreground">
                                        {deployment.author} · {deployment.time}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    );
}
