import { Link } from '@inertiajs/react';
import { MonitorIcon, MoonIcon, SunIcon } from '@phosphor-icons/react';

import { MarketingWordmark } from '@/components/marketing/marketing-header';
import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import {
    about,
    contact,
    features,
    login,
    pricing,
    privacy,
    register,
    security,
    terms,
} from '@/routes';

const footerColumns = [
    {
        title: 'Product',
        links: [
            { title: 'Features', href: features.url() },
            { title: 'Pricing', href: pricing.url() },
            { title: 'Security', href: security.url() },
        ],
    },
    {
        title: 'Company',
        links: [
            { title: 'About', href: about.url() },
            { title: 'Contact', href: contact.url() },
        ],
    },
    {
        title: 'Account',
        links: [
            { title: 'Log in', href: login.url() },
            { title: 'Create an account', href: register.url() },
        ],
    },
    {
        title: 'Legal',
        links: [
            { title: 'Terms of Service', href: terms.url() },
            { title: 'Privacy Policy', href: privacy.url() },
        ],
    },
];

const appearanceOptions: {
    value: Appearance;
    label: string;
    icon: typeof SunIcon;
}[] = [
    { value: 'light', label: 'Light', icon: SunIcon },
    { value: 'dark', label: 'Dark', icon: MoonIcon },
    { value: 'system', label: 'System', icon: MonitorIcon },
];

function AppearanceSwitch() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="radiogroup"
            aria-label="Theme"
            className="inline-flex items-center gap-0.5 rounded-full border border-border p-0.5"
        >
            {appearanceOptions.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={appearance === option.value}
                    aria-label={option.label}
                    onClick={() => updateAppearance(option.value)}
                    className={cn(
                        'flex size-7 items-center justify-center rounded-full transition-colors',
                        appearance === option.value
                            ? 'bg-muted text-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <option.icon className="size-3.5" />
                </button>
            ))}
        </div>
    );
}

export default function MarketingFooter() {
    return (
        <footer className="dark bg-neutral-950 text-foreground">
            <div className="mx-auto max-w-7xl px-6 pt-20 pb-10 lg:px-8">
                <div className="grid grid-cols-2 gap-x-8 gap-y-12 lg:grid-cols-[1.6fr_repeat(4,1fr)]">
                    <div className="col-span-2 max-w-xs lg:col-span-1">
                        <MarketingWordmark />
                        <p className="mt-5 leading-relaxed text-muted-foreground">
                            Provision, deploy, and run your applications on
                            servers you own.
                        </p>
                    </div>

                    {footerColumns.map((column) => (
                        <div key={column.title}>
                            <p className="font-medium">{column.title}</p>
                            <ul className="mt-5 space-y-3.5 text-[15px] text-muted-foreground">
                                {column.links.map((link) => (
                                    <li key={link.title}>
                                        <Link
                                            href={link.href}
                                            className="transition-colors hover:text-foreground"
                                        >
                                            {link.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="mt-20 flex flex-col-reverse items-start justify-between gap-6 border-t border-border pt-8 text-sm text-muted-foreground sm:flex-row sm:items-center">
                    <p>
                        &copy; {new Date().getFullYear()} flitops. All rights
                        reserved.
                    </p>
                    <AppearanceSwitch />
                </div>
            </div>
        </footer>
    );
}
