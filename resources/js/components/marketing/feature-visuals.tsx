import { CheckIcon, LockIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * Small, self-contained illustrations used inside the feature cards of the public site.
 */
export function CertificateVisual() {
    const domains = [
        { name: 'acme.com', expires: 'Renews in 61 days' },
        { name: 'www.acme.com', expires: 'Renews in 61 days' },
        { name: '*.preview.acme.com', expires: 'Wildcard · 84 days' },
    ];

    return (
        <ul className="space-y-2">
            {domains.map((domain) => (
                <li
                    key={domain.name}
                    className="flex items-center gap-3 rounded-lg bg-background px-3 py-2.5 text-xs ring-1 ring-border"
                >
                    <LockIcon className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span className="font-medium">{domain.name}</span>
                    <span className="ml-auto text-muted-foreground">
                        {domain.expires}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function BackupVisual() {
    const bars = [38, 42, 41, 47, 46, 52, 55, 54, 61, 63, 62, 68, 71, 70];

    return (
        <div className="rounded-lg bg-background p-3 ring-1 ring-border">
            <div className="flex items-center justify-between text-xs">
                <span className="font-medium">storefront_production</span>
                <span className="text-muted-foreground">Daily · 03:00 UTC</span>
            </div>
            <div className="mt-4 flex h-16 items-end gap-1">
                {bars.map((height, index) => (
                    <span
                        key={index}
                        style={{ height: `${height}%` }}
                        className={cn(
                            'flex-1 rounded-sm',
                            index === bars.length - 1
                                ? 'bg-brand'
                                : 'bg-brand/25',
                        )}
                    />
                ))}
            </div>
            <div className="mt-3 flex items-center justify-between text-[11px] text-muted-foreground">
                <span>14 backups kept on Cloudflare R2</span>
                <span className="font-medium text-foreground">Restore</span>
            </div>
        </div>
    );
}

export function TerminalVisual() {
    return (
        <div className="rounded-lg bg-neutral-950 p-3.5 font-log text-[11px] leading-relaxed ring-1 ring-white/10">
            <p className="text-white/45">Connected to fra-1 as flitops</p>
            <p className="mt-1 text-white/85">
                <span className="text-blue-400">~$</span> php artisan
                queue:monitor redis:default
            </p>
            <p className="text-white/45">[redis:default] 0 jobs waiting</p>
            <p className="text-white/85">
                <span className="text-blue-400">~$</span>{' '}
                <span className="inline-block h-3 w-1.5 translate-y-0.5 animate-pulse bg-white/70" />
            </p>
        </div>
    );
}

export function TeamVisual() {
    const members = [
        { initials: 'AO', name: 'Ada Okafor', role: 'Owner' },
        { initials: 'KM', name: 'Kofi Mensah', role: 'Admin' },
        { initials: 'LS', name: 'Lena Schmidt', role: 'Developer' },
        { initials: 'RT', name: 'Ravi Turner', role: 'Viewer' },
    ];

    return (
        <ul className="space-y-1.5">
            {members.map((member) => (
                <li
                    key={member.name}
                    className="flex items-center gap-2.5 text-xs"
                >
                    <span className="flex size-6 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">
                        {member.initials}
                    </span>
                    <span className="font-medium">{member.name}</span>
                    <span className="ml-auto rounded-full px-2 py-0.5 text-[10px] text-muted-foreground ring-1 ring-border">
                        {member.role}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function ProcessVisual() {
    const processes = [
        { name: 'horizon', detail: 'Queue worker · 3 processes' },
        { name: 'reverb:start', detail: 'Daemon · port 8080' },
        { name: 'schedule:run', detail: 'Cron · every minute' },
    ];

    return (
        <ul className="space-y-2">
            {processes.map((process) => (
                <li
                    key={process.name}
                    className="flex items-center gap-3 rounded-lg bg-background px-3 py-2.5 text-xs ring-1 ring-border"
                >
                    <span className="size-1.5 rounded-full bg-emerald-500" />
                    <span className="font-log font-medium">{process.name}</span>
                    <span className="ml-auto text-muted-foreground">
                        {process.detail}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function MetricsVisual() {
    const metrics = [
        { label: 'CPU', value: 23 },
        { label: 'Memory', value: 58 },
        { label: 'Disk', value: 41 },
    ];

    return (
        <div className="space-y-3.5">
            {metrics.map((metric) => (
                <div key={metric.label} className="text-xs">
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">
                            {metric.label}
                        </span>
                        <span className="font-medium tabular-nums">
                            {metric.value}%
                        </span>
                    </div>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-foreground/80"
                            style={{ width: `${metric.value}%` }}
                        />
                    </div>
                </div>
            ))}
            <p className="flex items-center gap-1.5 pt-1 text-xs text-muted-foreground">
                <CheckIcon className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                storefront.acme.com is up · checked 40s ago
            </p>
        </div>
    );
}
