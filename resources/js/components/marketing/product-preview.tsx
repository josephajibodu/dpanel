import {
    ArrowSquareOutIcon,
    BookOpenIcon,
    CaretRightIcon,
    CaretUpDownIcon,
    CloudIcon,
    CodeIcon,
    DotsThreeVerticalIcon,
    GearIcon,
    GitBranchIcon,
    HardDriveIcon,
    HardDrivesIcon,
    HouseIcon,
    KeyIcon,
    SidebarSimpleIcon,
} from '@phosphor-icons/react';
import { type ReactNode } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import { StatusBadge, type StatusColor } from '@/components/status-badge';
import { cn } from '@/lib/utils';

const sitePages = [
    'Overview',
    'Deployments',
    'Commands',
    'Processes',
    'Environment',
    'Database',
    'Deploy Script',
    'Web Server',
    'Domains',
    'Logs',
];

const integrations = [
    { title: 'SSH Keys', icon: KeyIcon },
    { title: 'Provider Accounts', icon: CloudIcon },
    { title: 'Storage Providers', icon: HardDriveIcon },
    { title: 'Source Control', icon: CodeIcon },
];

const deployments: {
    status: string;
    color: StatusColor;
    commit: string;
    message: string;
    deployed: string;
}[] = [
    {
        status: 'Running',
        color: 'blue',
        commit: 'a41f9c2',
        message: 'Add order export to CSV',
        deployed: 'Running...',
    },
    {
        status: 'Finished',
        color: 'green',
        commit: '7be03d1',
        message: 'Cache product listing queries',
        deployed: 'Nov 11, 12:08 by Kofi Mensah',
    },
    {
        status: 'Finished',
        color: 'green',
        commit: 'e19c55a',
        message: 'Upgrade to Laravel 13',
        deployed: 'Nov 10, 16:41 by Ada Okafor',
    },
    {
        status: 'Finished',
        color: 'green',
        commit: '3c0d8f7',
        message: 'Fix rounding on invoice totals',
        deployed: 'Nov 9, 09:15 by Ada Okafor',
    },
];

function SidebarLink({
    icon: Icon,
    children,
}: {
    icon: typeof HouseIcon;
    children: ReactNode;
}) {
    return (
        <li className="flex items-center gap-2.5 rounded-[10px] p-2 font-medium">
            <Icon className="size-[18px] shrink-0" />
            {children}
        </li>
    );
}

function OverviewItem({
    label,
    hint,
    className,
    children,
}: {
    label: string;
    hint: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('min-w-0 bg-card px-5 py-4', className)}>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1 truncate text-sm font-medium">{children}</dd>
            <dd className="mt-0.5 truncate text-xs text-muted-foreground">
                {hint}
            </dd>
        </div>
    );
}

/**
 * A static copy of a site's overview page in the flitops dashboard, shown in
 * the home page hero. It mirrors the real layout: sidebar, top bar, summary
 * card, and deployments table.
 */
export default function ProductPreview() {
    return (
        <div
            role="img"
            aria-label="The flitops dashboard showing a site's overview while a deployment runs"
            className="flex overflow-hidden rounded-t-2xl bg-sidebar font-sans text-sm text-foreground shadow-overlay select-none [--text-sm--line-height:calc(20/13)] [--text-sm:0.8125rem] [font-feature-settings:'cv11','ss01']"
        >
            <aside className="hidden w-56 shrink-0 flex-col p-2 text-sidebar-foreground lg:flex">
                <div className="flex h-9 items-center gap-2 px-1.5 text-sidebar-accent-foreground">
                    <span className="flex size-6 items-center justify-center rounded-md bg-brand text-white">
                        <AppLogoIcon className="size-3.5 fill-current" />
                    </span>
                    <span className="font-semibold">Acme Inc.</span>
                    <CaretUpDownIcon className="ml-auto size-3.5 text-sidebar-foreground/60" />
                </div>

                <ul className="mt-3 space-y-0.5">
                    <SidebarLink icon={HouseIcon}>Dashboard</SidebarLink>
                    <li>
                        <div className="flex items-center gap-2.5 rounded-[10px] p-2 font-medium text-sidebar-accent-foreground">
                            <HardDrivesIcon
                                weight="fill"
                                className="size-[18px] shrink-0"
                            />
                            Servers
                        </div>
                        <ul className="space-y-0.5 pt-0.5 pb-1">
                            {sitePages.map((page, index) => (
                                <li
                                    key={page}
                                    className={cn(
                                        'flex h-[30px] items-center rounded-[10px] pl-[34px]',
                                        index === 0
                                            ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                            : 'text-sidebar-foreground/70',
                                    )}
                                >
                                    {page}
                                </li>
                            ))}
                        </ul>
                    </li>
                </ul>

                <p className="mt-2 flex h-8 items-center px-2 text-sidebar-foreground/60">
                    Integrations
                </p>
                <ul className="space-y-0.5">
                    {integrations.map((item) => (
                        <SidebarLink key={item.title} icon={item.icon}>
                            {item.title}
                        </SidebarLink>
                    ))}
                </ul>

                <ul className="mt-auto space-y-0.5 pt-6">
                    <SidebarLink icon={BookOpenIcon}>Documentation</SidebarLink>
                    <SidebarLink icon={GearIcon}>Settings</SidebarLink>
                </ul>
            </aside>

            <div className="m-2 flex min-w-0 flex-1 flex-col rounded-2xl bg-background lg:ml-0">
                <div className="flex h-13 shrink-0 items-center gap-2 px-4">
                    <SidebarSimpleIcon className="size-[18px] shrink-0 text-muted-foreground" />
                    <HardDrivesIcon className="ml-1 size-[18px] shrink-0 text-muted-foreground" />
                    <div className="flex min-w-0 items-center gap-1.5 text-muted-foreground">
                        <span className="hidden sm:inline">Servers</span>
                        <CaretRightIcon className="hidden size-3 sm:inline" />
                        <span className="hidden sm:inline">fra-1</span>
                        <CaretRightIcon className="hidden size-3 sm:inline" />
                        <span className="truncate font-semibold text-foreground">
                            storefront.acme.com
                        </span>
                    </div>
                    <div className="ml-auto flex shrink-0 items-center gap-2">
                        <span className="hidden h-8 items-center gap-2 rounded-full bg-card px-3 font-medium shadow-button sm:inline-flex">
                            <ArrowSquareOutIcon className="size-4" />
                            Visit
                        </span>
                        <span className="inline-flex h-8 items-center rounded-full bg-primary bg-linear-to-b from-white/10 to-transparent px-3.5 font-medium text-primary-foreground shadow-button-primary">
                            Deploy
                        </span>
                        <span className="hidden size-8 items-center justify-center rounded-full bg-card shadow-button sm:inline-flex">
                            <DotsThreeVerticalIcon
                                weight="bold"
                                className="size-4"
                            />
                        </span>
                    </div>
                </div>

                <div className="flex flex-col gap-6 p-4">
                    <section className="overflow-hidden rounded-2xl bg-card shadow-card">
                        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-4">
                            <div className="flex min-w-0 items-center gap-2.5">
                                <p className="truncate text-lg font-semibold tracking-tight">
                                    storefront.acme.com
                                </p>
                                <StatusBadge
                                    status="Deploying"
                                    color="blue"
                                    pulse
                                />
                            </div>
                            <div className="hidden items-center gap-x-4 md:flex">
                                <span className="inline-flex items-center gap-1 text-link">
                                    storefront.acme.com
                                    <ArrowSquareOutIcon className="size-3.5" />
                                </span>
                                <span className="text-muted-foreground">
                                    Manage domains →
                                </span>
                            </div>
                        </div>

                        <dl className="grid grid-cols-2 gap-px border-t bg-border md:grid-cols-4">
                            <OverviewItem
                                label="Source"
                                className="col-span-2"
                                hint={
                                    <>
                                        <span className="font-mono">
                                            a41f9c2
                                        </span>
                                        {' · Add order export to CSV'}
                                    </>
                                }
                            >
                                <span className="flex items-center gap-1.5">
                                    <GitBranchIcon className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="truncate font-mono">
                                        acme/storefront:main
                                    </span>
                                </span>
                            </OverviewItem>
                            <OverviewItem
                                label="Uptime"
                                hint="Checked less than a minute ago"
                            >
                                <StatusBadge status="Up" color="green" />
                            </OverviewItem>
                            <OverviewItem label="Framework" hint="PHP 8.4">
                                Laravel
                            </OverviewItem>
                            <OverviewItem
                                label="Server"
                                hint={
                                    <span className="font-mono">
                                        203.0.113.24
                                    </span>
                                }
                            >
                                <span className="text-link">fra-1</span>
                            </OverviewItem>
                            <OverviewItem
                                label="Database"
                                hint="On fra-1 · Connection details"
                            >
                                <span className="font-mono text-link">
                                    storefront
                                </span>
                            </OverviewItem>
                            <OverviewItem
                                label="Auto deploy"
                                hint="On push to main"
                            >
                                Enabled
                            </OverviewItem>
                            <OverviewItem label="Created" hint="8 months ago">
                                Mar 3, 2026
                            </OverviewItem>
                        </dl>
                    </section>

                    <div className="space-y-4">
                        <div className="space-y-1">
                            <p className="text-[15px] leading-snug font-semibold">
                                Deployments
                            </p>
                            <p className="text-muted-foreground">
                                Recent deployments for this site.
                            </p>
                        </div>
                        <div>
                            <div className="grid h-9 grid-cols-[6.5rem_1fr] items-center rounded-lg bg-muted px-4 font-medium text-muted-foreground sm:grid-cols-[6.5rem_6rem_1fr_15rem]">
                                <span>Status</span>
                                <span>Commit</span>
                                <span className="hidden sm:block">Message</span>
                                <span className="hidden sm:block">
                                    Deployed
                                </span>
                            </div>
                            {deployments.map((deployment) => (
                                <div
                                    key={deployment.commit}
                                    className="grid grid-cols-[6.5rem_1fr] items-center border-b border-border px-4 py-2.5 last:border-b-0 sm:grid-cols-[6.5rem_6rem_1fr_15rem]"
                                >
                                    <span>
                                        <StatusBadge
                                            status={deployment.status}
                                            color={deployment.color}
                                            pulse={
                                                deployment.status === 'Running'
                                            }
                                        />
                                    </span>
                                    <span className="font-mono">
                                        {deployment.commit}
                                    </span>
                                    <span className="hidden truncate text-muted-foreground sm:block">
                                        {deployment.message}
                                    </span>
                                    <span className="hidden truncate text-muted-foreground sm:block">
                                        {deployment.deployed}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
