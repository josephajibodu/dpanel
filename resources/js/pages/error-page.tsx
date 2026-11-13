import { Head, Link, usePage } from '@inertiajs/react';

import { MarketingWordmark } from '@/components/marketing/marketing-header';
import Scene from '@/components/marketing/scene';
import SectionHeading from '@/components/marketing/section-heading';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { contact } from '@/routes';

type TerminalLine = { kind: 'command' | 'output' | 'error'; text: string };

type ErrorCopy = {
    title: string;
    description: string;
    terminal: (path: string) => TerminalLine[];
};

const errors: Record<number, ErrorCopy> = {
    403: {
        title: "You don't have access",
        description: "You don't have permission to view this page.",
        terminal: (path) => [
            { kind: 'command', text: `cd ${path}` },
            { kind: 'error', text: `cd: ${path}: Permission denied` },
        ],
    },
    404: {
        title: 'Page not found',
        description:
            "The page you're looking for doesn't exist or may have been deleted.",
        terminal: (path) => [
            { kind: 'command', text: `cd ${path}` },
            { kind: 'error', text: `cd: ${path}: No such file or directory` },
        ],
    },
    419: {
        title: 'Page expired',
        description: 'Your session timed out. Refresh the page and try again.',
        terminal: () => [
            { kind: 'command', text: 'ssh flitops' },
            { kind: 'error', text: 'Connection closed: session timed out' },
        ],
    },
    429: {
        title: 'Too many requests',
        description:
            "You've made too many requests. Wait a minute and try again.",
        terminal: (path) => [
            { kind: 'command', text: `curl -I ${path}` },
            { kind: 'error', text: 'HTTP/2 429 Too Many Requests' },
            { kind: 'output', text: 'retry-after: 60' },
        ],
    },
    500: {
        title: 'Something went wrong',
        description:
            'Something went wrong on our end. Please try again in a moment.',
        terminal: (path) => [
            { kind: 'command', text: 'tail -n 1 storage/logs/laravel.log' },
            {
                kind: 'error',
                text: `production.ERROR: Failed to load ${path}`,
            },
        ],
    },
    503: {
        title: 'Down for maintenance',
        description:
            "We're doing some quick maintenance. Please try again in a few minutes.",
        terminal: () => [
            { kind: 'command', text: 'php artisan down' },
            {
                kind: 'output',
                text: 'INFO  Application is now in maintenance mode.',
            },
        ],
    },
};

const fallback: ErrorCopy = {
    title: 'Something went wrong',
    description: 'Something went wrong. Please try again.',
    terminal: (path) => [
        { kind: 'command', text: `curl -I ${path}` },
        { kind: 'error', text: 'Request failed' },
    ],
};

/**
 * Shown in production when a page fails to load, in place of Laravel's
 * plain error page. The terminal on the right acts out the error. A 403
 * carries its own message when it has one, such as the plan upgrade
 * message.
 */
export default function ErrorPage({
    status,
    message,
}: {
    status: number;
    message: string;
}) {
    const path = usePage().url.split('?')[0];
    const error = errors[status] ?? fallback;

    return (
        <>
            <Head title={error.title} />

            <div className="flex min-h-svh flex-col bg-background font-marketing text-foreground antialiased [font-feature-settings:normal]">
                <header className="px-6 lg:px-8">
                    <div className="mx-auto flex h-16 max-w-7xl items-center">
                        <MarketingWordmark />
                    </div>
                </header>

                <main className="flex flex-1 items-center px-6 py-12 lg:px-8">
                    <div className="mx-auto grid w-full max-w-7xl items-center gap-12 lg:grid-cols-2 lg:gap-20">
                        <div>
                            <SectionHeading
                                as="h1"
                                eyebrow={`Error ${status}`}
                                title={error.title}
                                description={message || error.description}
                            />

                            <div className="mt-10 flex flex-col gap-3 sm:flex-row">
                                <Button
                                    asChild
                                    size="lg"
                                    className="h-12 px-7 text-base"
                                >
                                    <Link href="/dashboard">
                                        Go to dashboard
                                    </Link>
                                </Button>
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="h-12 px-7 text-base"
                                    onClick={() => window.history.back()}
                                >
                                    Go back
                                </Button>
                            </div>

                            <p className="mt-8 text-sm text-muted-foreground">
                                Still stuck?{' '}
                                <Link
                                    href={contact()}
                                    className="font-medium text-foreground underline underline-offset-4"
                                >
                                    Contact us
                                </Link>{' '}
                                and tell us what you were doing.
                            </p>
                        </div>

                        <Scene
                            tone="brand"
                            className="rounded-3xl px-5 pt-6 pb-8 sm:px-10 sm:pt-8 sm:pb-12"
                        >
                            <p
                                aria-hidden="true"
                                className="text-[7rem] leading-none font-normal tracking-[-0.06em] text-white/25 select-none sm:text-[11rem]"
                            >
                                {status}
                            </p>
                            <ErrorTerminal lines={error.terminal(path)} />
                        </Scene>
                    </div>
                </main>
            </div>
        </>
    );
}

/**
 * A terminal window that replays the error as a short shell session.
 */
function ErrorTerminal({ lines }: { lines: TerminalLine[] }) {
    return (
        <div
            aria-hidden="true"
            className="-mt-6 overflow-hidden rounded-xl bg-neutral-950 font-log text-xs leading-relaxed ring-1 ring-white/10 sm:-mt-10 sm:text-[13px]"
        >
            <div className="flex items-center gap-1.5 border-b border-white/10 px-4 py-3">
                <span className="size-2.5 rounded-full bg-white/15" />
                <span className="size-2.5 rounded-full bg-white/15" />
                <span className="size-2.5 rounded-full bg-white/15" />
                <span className="ml-3 text-white/45">flitops — ssh</span>
            </div>

            <div className="space-y-1 px-4 py-4 sm:px-5 sm:py-5">
                <p className="text-white/55">Connected to flitops</p>
                {lines.map((line, index) => (
                    <p
                        key={index}
                        className={cn(
                            'break-words whitespace-pre-wrap',
                            line.kind === 'command' && 'text-white/85',
                            line.kind === 'output' && 'text-white/55',
                            line.kind === 'error' && 'text-red-300',
                        )}
                    >
                        {line.kind === 'command' && (
                            <span className="text-blue-400">~$ </span>
                        )}
                        {line.text}
                    </p>
                ))}
                <p className="text-white/85">
                    <span className="text-blue-400">~$</span>{' '}
                    <span className="inline-block h-3.5 w-1.5 translate-y-0.5 animate-pulse bg-white/70" />
                </p>
            </div>
        </div>
    );
}
