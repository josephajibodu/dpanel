import { Link } from '@inertiajs/react';
import { MonitorIcon, MoonIcon, SunIcon } from 'lucide-react';

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
        <footer className="border-t border-border">
            <div className="mx-auto max-w-6xl px-6 pt-16 pb-10">
                <div className="grid gap-12 lg:grid-cols-[1.4fr_repeat(4,1fr)]">
                    <div className="max-w-xs">
                        <MarketingWordmark />
                        <p className="mt-4 text-sm leading-relaxed text-muted-foreground">
                            Provision, deploy, and run your applications on
                            servers you own, with the polish of a managed
                            platform.
                        </p>
                    </div>

                    {footerColumns.map((column) => (
                        <div key={column.title}>
                            <p className="text-sm font-medium">
                                {column.title}
                            </p>
                            <ul className="mt-4 space-y-3 text-sm">
                                {column.links.map((link) => (
                                    <li key={link.title}>
                                        <Link
                                            href={link.href}
                                            className="text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            {link.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="mt-16 flex flex-col-reverse items-start justify-between gap-6 border-t border-border pt-8 text-sm text-muted-foreground sm:flex-row sm:items-center">
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
