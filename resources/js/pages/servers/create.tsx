import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ArrowsClockwiseIcon,
    CircleNotchIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useState } from 'react';

import {
    FormSection,
    RadioDot,
    RadioOption,
    SegmentedControl,
    SummaryCard,
} from '@/components/create-form';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { ProviderAccount } from '@/types/provider-account';
import {
    ProviderRegion,
    ProviderSize,
    ProviderSizeOffer,
    ServerTypeOption,
    ServerTypeValue,
} from '@/types/server';

interface Props {
    providerAccounts: {
        data: ProviderAccount[];
    };
    generatedName: string;
    serverTypes: ServerTypeOption[];
}

type Mode = 'provider' | 'custom';

const phpVersions = [
    { value: '8.5', label: 'PHP 8.5' },
    { value: '8.4', label: 'PHP 8.4' },
    { value: '8.3', label: 'PHP 8.3' },
    { value: '8.2', label: 'PHP 8.2' },
    { value: '8.1', label: 'PHP 8.1' },
];

const webServers = [
    { value: 'nginx', label: 'Nginx' },
    { value: 'caddy', label: 'Caddy (automatic HTTPS)' },
];

const databaseTypes = [
    { value: 'mysql', label: 'MySQL 8' },
    { value: 'postgresql', label: 'PostgreSQL' },
    { value: 'mariadb', label: 'MariaDB' },
];

/** Select value standing in for "don't install this" (Select items can't be empty). */
const NONE = 'none';

/** The server type and the services chosen for it, as sent to the server. */
interface Stack {
    type: ServerTypeValue;
    php_version: string | null;
    web_server: string | null;
    database_type: string | null;
    redis: boolean;
}

/**
 * Apply a type's preset: tick or untick each service, keeping the version or
 * engine the user already picked for services that stay ticked.
 */
function applyPreset(option: ServerTypeOption, current: Stack): Stack {
    return {
        type: option.value,
        php_version: option.services.php
            ? (current.php_version ?? '8.5')
            : null,
        web_server: option.services.web_server
            ? (current.web_server ?? 'nginx')
            : null,
        database_type: option.services.database
            ? (current.database_type ?? 'mysql')
            : null,
        redis: option.services.redis,
    };
}

const defaultStack: Stack = {
    type: 'app',
    php_version: '8.5',
    web_server: 'nginx',
    database_type: 'mysql',
    redis: true,
};

/** The state of fetching one provider account's regions and sizes. */
type Catalog =
    | { status: 'loading' }
    | { status: 'ready'; regions: ProviderRegion[]; sizes: ProviderSize[] }
    | { status: 'error'; message: string };

/**
 * Fetch each provider account's regions and sizes the first time it's picked,
 * and keep the answer so switching back to that account is instant.
 */
function useProviderCatalogs() {
    const teamPath = useTeamPath();
    const [catalogs, setCatalogs] = useState<Record<number, Catalog>>({});

    const load = useCallback(
        async (accountId: number) => {
            setCatalogs((current) => ({
                ...current,
                [accountId]: { status: 'loading' },
            }));

            let catalog: Catalog;
            try {
                const response = await fetch(
                    teamPath(`/provider-accounts/${accountId}/catalog`),
                    {
                        headers: { Accept: 'application/json' },
                    },
                );
                const body = (await response.json().catch(() => ({}))) as {
                    regions?: ProviderRegion[];
                    sizes?: ProviderSize[];
                    message?: string;
                };

                catalog = response.ok
                    ? {
                          status: 'ready',
                          regions: body.regions ?? [],
                          sizes: body.sizes ?? [],
                      }
                    : {
                          status: 'error',
                          message:
                              body.message ??
                              'Could not load regions and sizes.',
                      };
            } catch {
                catalog = {
                    status: 'error',
                    message:
                        'Could not reach FlitOps. Check your connection and try again.',
                };
            }

            setCatalogs((current) => ({ ...current, [accountId]: catalog }));
        },
        [teamPath],
    );

    return { catalogs, load };
}

function formatMemory(megabytes: number): string {
    if (megabytes < 1024) {
        return `${megabytes} MB`;
    }

    const gigabytes = megabytes / 1024;

    return `${Number.isInteger(gigabytes) ? gigabytes : gigabytes.toFixed(1)} GB`;
}

function formatPrice(price: number, currency: string): string {
    return new Intl.NumberFormat('en', { style: 'currency', currency }).format(
        price,
    );
}

/** Hourly prices are fractions of a cent, so they keep more decimals. */
function formatHourlyPrice(price: number, currency: string): string {
    return new Intl.NumberFormat('en', {
        style: 'currency',
        currency,
        minimumFractionDigits: 3,
        maximumFractionDigits: 4,
    }).format(price);
}

function formatTransfer(terabytes: number): string {
    return `${Number(terabytes.toFixed(2))} TB`;
}

const cpuTypeLabels = { shared: 'Shared vCPU', dedicated: 'Dedicated vCPU' };

const architectureLabels = { x86: 'x86', arm: 'Arm64' };

/** The processor a size runs on, e.g. "x86 (AMD)" or "Arm64". */
function formatProcessor(size: ProviderSize): string | null {
    const architecture = size.architecture
        ? architectureLabels[size.architecture]
        : null;

    if (architecture && size.cpu_vendor) {
        return `${architecture} (${size.cpu_vendor})`;
    }

    return architecture ?? size.cpu_vendor;
}

/** What sets one group of sizes apart, e.g. "Cost-optimized · Shared vCPU · Arm64". */
function describeSizeGroup(size: ProviderSize): string {
    return [
        size.category,
        size.cpu_type ? cpuTypeLabels[size.cpu_type] : null,
        formatProcessor(size),
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Whether the provider lists this size in the given region. */
function isSizeListedIn(size: ProviderSize, regionSlug: string): boolean {
    return size.regions === null || size.regions.includes(regionSlug);
}

/** Whether the provider can create this size in the given region right now. */
function isSizeInStockIn(size: ProviderSize, regionSlug: string): boolean {
    return !size.unavailable_regions.includes(regionSlug);
}

/** The size's prices before tax and included traffic in the given region. */
function sizeOfferIn(
    size: ProviderSize,
    regionSlug: string,
): ProviderSizeOffer {
    return {
        price_monthly: size.price_monthly,
        price_hourly: size.price_hourly,
        transfer: size.transfer,
        ...size.region_overrides[regionSlug],
    };
}

/** What each provider leaves out of, or folds into, the prices shown here. */
const providerPricing: Record<
    ProviderAccount['provider'],
    { note: string; url: string }
> = {
    hetzner: {
        note: 'Prices exclude VAT.',
        url: 'https://www.hetzner.com/cloud/',
    },
    digitalocean: {
        note: 'Includes a public IPv4 address. Traffic beyond the included amount costs $0.01 per GB.',
        url: 'https://www.digitalocean.com/pricing/droplets',
    },
    vultr: {
        note: 'Includes a public IPv4 address. Traffic beyond the included amount is billed by Vultr.',
        url: 'https://www.vultr.com/pricing/',
    },
    aws: {
        note: 'An estimate from us-east-1 prices; other regions can cost more. Outgoing traffic is billed per GB after the first 100 GB a month, and T3 sizes cost extra while their CPU stays busy above its baseline.',
        url: 'https://aws.amazon.com/ec2/pricing/on-demand/',
    },
};

export default function ServersCreate({
    providerAccounts,
    generatedName,
    serverTypes,
}: Props) {
    const teamPath = useTeamPath();
    const accounts = providerAccounts.data;
    const { url } = usePage();
    const requestedMode = new URLSearchParams(url.split('?')[1] ?? '').get(
        'mode',
    );
    const [mode, setMode] = useState<Mode>(
        requestedMode === 'custom' || accounts.length === 0
            ? 'custom'
            : 'provider',
    );
    const [isGeneratingName, setIsGeneratingName] = useState(false);
    const { catalogs, load: loadCatalog } = useProviderCatalogs();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: 'Create Server', href: teamPath('/servers/create') },
    ];

    const providerForm = useForm({
        name: generatedName ?? '',
        provider_account_id:
            accounts.length === 1 ? accounts[0].id.toString() : '',
        region: '',
        size: '',
        ...defaultStack,
    });

    const customForm = useForm({
        name: generatedName ?? '',
        ip_address: '',
        ssh_port: '22',
        ...defaultStack,
    });

    const selectedAccountId = providerForm.data.provider_account_id
        ? parseInt(providerForm.data.provider_account_id)
        : null;
    const selectedAccount =
        accounts.find((account) => account.id === selectedAccountId) ?? null;
    const catalog = selectedAccountId ? catalogs[selectedAccountId] : undefined;

    // Load the chosen account's regions and sizes once the page is on screen.
    useEffect(() => {
        if (selectedAccountId && !catalogs[selectedAccountId]) {
            void loadCatalog(selectedAccountId);
        }
    }, [selectedAccountId, catalogs, loadCatalog]);

    const regions = catalog?.status === 'ready' ? catalog.regions : [];
    const sizes = catalog?.status === 'ready' ? catalog.sizes : [];
    const selectedRegion =
        regions.find((region) => region.slug === providerForm.data.region) ??
        null;
    const selectedSize =
        sizes.find((size) => size.slug === providerForm.data.size) ?? null;
    const sizeOffer =
        selectedSize && selectedRegion
            ? sizeOfferIn(selectedSize, selectedRegion.slug)
            : null;

    // Keep the chosen size only if the new region can create it too.
    const handleRegionChange = (regionSlug: string) => {
        const sizeStillAvailable =
            selectedSize !== null &&
            isSizeListedIn(selectedSize, regionSlug) &&
            isSizeInStockIn(selectedSize, regionSlug);

        providerForm.setData({
            ...providerForm.data,
            region: regionSlug,
            size: sizeStillAvailable ? providerForm.data.size : '',
        });
    };

    const handleProviderSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        providerForm.post(teamPath('/servers'));
    };

    const handleCustomSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        customForm.post(teamPath('/servers/custom'));
    };

    const handleGenerateName = async () => {
        setIsGeneratingName(true);
        try {
            const response = await fetch(teamPath('/servers/generate-name'), {
                headers: { Accept: 'application/json' },
            });
            if (response.ok) {
                const result = (await response.json()) as { name?: string };
                if (result.name) {
                    if (mode === 'provider') {
                        providerForm.setData('name', result.name);
                    } else {
                        customForm.setData('name', result.name);
                    }
                }
            }
        } catch {
            // Keep the current name if generation fails.
        } finally {
            setIsGeneratingName(false);
        }
    };

    const handleAccountChange = (accountId: number) => {
        if (accountId === selectedAccountId) {
            return;
        }

        providerForm.setData({
            ...providerForm.data,
            provider_account_id: accountId.toString(),
            region: '',
            size: '',
        });
    };

    const activeForm = mode === 'provider' ? providerForm : customForm;
    const stackType = serverTypes.find(
        (option) => option.value === activeForm.data.type,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Server" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={teamPath('/servers')}
                                aria-label="Back to servers"
                            >
                                <ArrowLeftIcon className="h-4 w-4" />
                            </Link>
                        </Button>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Create Server
                        </h1>
                    </div>

                    <SegmentedControl
                        label="How to add the server"
                        value={mode}
                        onChange={setMode}
                        options={[
                            { value: 'provider', label: 'New server' },
                            { value: 'custom', label: 'Existing server' },
                        ]}
                    />
                </div>

                {mode === 'provider' ? (
                    <form
                        onSubmit={handleProviderSubmit}
                        className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_280px]"
                    >
                        <Card className="gap-0 divide-y py-0">
                            <FormSection
                                title="Provider"
                                description="The cloud account the server is created in."
                            >
                                {accounts.length === 0 ? (
                                    <EmptyState
                                        title="No provider accounts connected"
                                        description="Connect a DigitalOcean, Hetzner, Vultr or AWS account to create servers here."
                                        action={
                                            <Button asChild size="sm">
                                                <Link
                                                    href={teamPath(
                                                        '/provider-accounts/create',
                                                    )}
                                                >
                                                    Connect a provider
                                                </Link>
                                            </Button>
                                        }
                                    />
                                ) : (
                                    <Select
                                        value={
                                            providerForm.data
                                                .provider_account_id
                                        }
                                        onValueChange={(value) =>
                                            handleAccountChange(parseInt(value))
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label="Provider account"
                                            className="w-full sm:max-w-sm"
                                        >
                                            <SelectValue placeholder="Select a provider account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {accounts.map((account) => (
                                                <SelectItem
                                                    key={account.id}
                                                    value={account.id.toString()}
                                                >
                                                    {account.name}
                                                    <span className="text-muted-foreground">
                                                        {account.provider_label}
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <InputError
                                    message={
                                        providerForm.errors.provider_account_id
                                    }
                                />
                            </FormSection>

                            <FormSection title="Region and size">
                                <CatalogSection
                                    account={selectedAccount}
                                    catalog={catalog}
                                    onRetry={() =>
                                        selectedAccountId &&
                                        void loadCatalog(selectedAccountId)
                                    }
                                    region={providerForm.data.region}
                                    size={providerForm.data.size}
                                    onRegionChange={handleRegionChange}
                                    onSizeChange={(value) =>
                                        providerForm.setData('size', value)
                                    }
                                    regionError={providerForm.errors.region}
                                    sizeError={providerForm.errors.size}
                                />
                            </FormSection>

                            <FormSection
                                title="Name"
                                description="Also used as the server's hostname."
                            >
                                <NameField
                                    id="name"
                                    value={providerForm.data.name}
                                    onChange={(value) =>
                                        providerForm.setData('name', value)
                                    }
                                    onGenerate={handleGenerateName}
                                    isGenerating={isGeneratingName}
                                    error={providerForm.errors.name}
                                />
                            </FormSection>

                            <StackSection
                                serverTypes={serverTypes}
                                stack={providerForm.data}
                                onChange={(stack) =>
                                    providerForm.setData({
                                        ...providerForm.data,
                                        ...stack,
                                    })
                                }
                                errors={providerForm.errors}
                            />
                        </Card>

                        <SummaryCard
                            rows={[
                                {
                                    label: 'Provider',
                                    value:
                                        selectedAccount?.provider_label ?? null,
                                },
                                {
                                    label: 'Region',
                                    value: selectedRegion?.name ?? null,
                                },
                                {
                                    label: 'Size',
                                    value: selectedSize
                                        ? (selectedSize.label ??
                                          selectedSize.slug)
                                        : null,
                                },
                                ...(selectedSize
                                    ? sizeSpecRows(selectedSize, sizeOffer)
                                    : []),
                                {
                                    label: 'Type',
                                    value: stackType?.label ?? null,
                                },
                            ]}
                            submitLabel="Create server"
                            processing={providerForm.processing}
                            disabled={accounts.length === 0}
                            cancelHref={teamPath('/servers')}
                        >
                            {selectedAccount &&
                            selectedRegion &&
                            selectedSize &&
                            sizeOffer?.price_monthly ? (
                                <CostBreakdown
                                    costs={{
                                        currency: selectedSize.currency,
                                        lines: [
                                            {
                                                label: 'Server',
                                                amount: sizeOffer.price_monthly,
                                            },
                                            ...selectedRegion.extra_charges.map(
                                                (charge) => ({
                                                    label: charge.label,
                                                    amount: charge.price_monthly,
                                                }),
                                            ),
                                        ],
                                        hourly: sizeOffer.price_hourly,
                                        trafficPricePerTb:
                                            selectedRegion.traffic_price_per_tb,
                                        ...providerPricing[
                                            selectedAccount.provider
                                        ],
                                    }}
                                />
                            ) : null}
                        </SummaryCard>
                    </form>
                ) : (
                    <form
                        onSubmit={handleCustomSubmit}
                        className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_280px]"
                    >
                        <Card className="gap-0 divide-y py-0">
                            <FormSection
                                title="Connection"
                                description="Any fresh Ubuntu server. The next step shows how to give FlitOps access."
                            >
                                <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_120px]">
                                    <div className="space-y-2">
                                        <Label htmlFor="ip_address">
                                            IP address
                                        </Label>
                                        <Input
                                            id="ip_address"
                                            type="text"
                                            value={customForm.data.ip_address}
                                            onChange={(e) =>
                                                customForm.setData(
                                                    'ip_address',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g., 203.0.113.10"
                                            required
                                        />
                                        <InputError
                                            message={
                                                customForm.errors.ip_address
                                            }
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="ssh_port">
                                            SSH port
                                        </Label>
                                        <Input
                                            id="ssh_port"
                                            type="number"
                                            min={1}
                                            max={65535}
                                            value={customForm.data.ssh_port}
                                            onChange={(e) =>
                                                customForm.setData(
                                                    'ssh_port',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={customForm.errors.ssh_port}
                                        />
                                    </div>
                                </div>
                            </FormSection>

                            <FormSection
                                title="Name"
                                description="Also used as the server's hostname."
                            >
                                <NameField
                                    id="custom-name"
                                    value={customForm.data.name}
                                    onChange={(value) =>
                                        customForm.setData('name', value)
                                    }
                                    onGenerate={handleGenerateName}
                                    isGenerating={isGeneratingName}
                                    error={customForm.errors.name}
                                />
                            </FormSection>

                            <StackSection
                                serverTypes={serverTypes}
                                stack={customForm.data}
                                onChange={(stack) =>
                                    customForm.setData({
                                        ...customForm.data,
                                        ...stack,
                                    })
                                }
                                errors={customForm.errors}
                            />
                        </Card>

                        <SummaryCard
                            rows={[
                                {
                                    label: 'IP address',
                                    value: customForm.data.ip_address || null,
                                },
                                {
                                    label: 'SSH port',
                                    value: customForm.data.ssh_port || null,
                                },
                                {
                                    label: 'Type',
                                    value: stackType?.label ?? null,
                                },
                            ]}
                            submitLabel="Connect server"
                            processing={customForm.processing}
                            disabled={false}
                            cancelHref={teamPath('/servers')}
                        />
                    </form>
                )}
            </div>
        </AppLayout>
    );
}

interface NameFieldProps {
    id: string;
    value: string;
    onChange: (value: string) => void;
    onGenerate: () => void;
    isGenerating: boolean;
    error?: string;
}

function NameField({
    id,
    value,
    onChange,
    onGenerate,
    isGenerating,
    error,
}: NameFieldProps) {
    return (
        <div className="space-y-2">
            <div className="flex gap-2">
                <Input
                    id={id}
                    type="text"
                    aria-label="Server name"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="e.g., production-api"
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={onGenerate}
                    disabled={isGenerating}
                    aria-label="Generate a new name"
                >
                    {isGenerating ? (
                        <CircleNotchIcon className="h-4 w-4 animate-spin" />
                    ) : (
                        <ArrowsClockwiseIcon className="h-4 w-4" />
                    )}
                </Button>
            </div>
            <InputError message={error} />
        </div>
    );
}

interface CatalogSectionProps {
    account: ProviderAccount | null;
    catalog: Catalog | undefined;
    onRetry: () => void;
    region: string;
    size: string;
    onRegionChange: (value: string) => void;
    onSizeChange: (value: string) => void;
    regionError?: string;
    sizeError?: string;
}

/**
 * The region picker and size list, with clear loading, error and empty states
 * while the provider's options are fetched.
 */
function CatalogSection({
    account,
    catalog,
    onRetry,
    region,
    size,
    onRegionChange,
    onSizeChange,
    regionError,
    sizeError,
}: CatalogSectionProps) {
    if (!account) {
        return (
            <div className="space-y-2">
                <p className="text-sm text-muted-foreground">
                    Choose a provider to see its regions and sizes.
                </p>
                <InputError message={regionError} />
                <InputError message={sizeError} />
            </div>
        );
    }

    if (!catalog || catalog.status === 'loading') {
        return (
            <div className="space-y-3" aria-busy="true">
                <p
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <CircleNotchIcon className="h-4 w-4 animate-spin" />
                    Loading from {account.provider_label}…
                </p>
                <Skeleton className="h-9 w-full" />
                <Skeleton className="h-32 w-full" />
            </div>
        );
    }

    if (catalog.status === 'error') {
        return (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3">
                <p className="text-sm text-destructive">{catalog.message}</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onRetry}
                >
                    <ArrowsClockwiseIcon className="h-4 w-4" />
                    Try again
                </Button>
            </div>
        );
    }

    // Only offer regions where the provider lists at least one size.
    const regions = catalog.regions.filter((option) =>
        catalog.sizes.some((sizeOption) =>
            isSizeListedIn(sizeOption, option.slug),
        ),
    );

    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor="region">Region</Label>
                <Select value={region} onValueChange={onRegionChange}>
                    <SelectTrigger id="region" className="w-full sm:max-w-sm">
                        <SelectValue placeholder="Select a region" />
                    </SelectTrigger>
                    <SelectContent>
                        {regions.map((option) => (
                            <SelectItem key={option.slug} value={option.slug}>
                                {option.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={regionError} />
            </div>

            <div className="space-y-2">
                <Label id="size-label">Size</Label>
                {region === '' ? (
                    <p className="text-sm text-muted-foreground">
                        Choose a region to see the sizes{' '}
                        {account.provider_label} offers there.
                    </p>
                ) : (
                    <SizeTable
                        sizes={catalog.sizes.filter((option) =>
                            isSizeListedIn(option, region),
                        )}
                        region={region}
                        selected={size}
                        onSelect={onSizeChange}
                    />
                )}
                <InputError message={sizeError} />
            </div>
        </div>
    );
}

interface SizeTableProps {
    sizes: ProviderSize[];
    region: string;
    selected: string;
    onSelect: (slug: string) => void;
}

/** The chosen size's hardware and included traffic, as rows for the summary. */
function sizeSpecRows(
    size: ProviderSize,
    offer: ProviderSizeOffer | null,
): { label: string; value: string | null }[] {
    const processor = formatProcessor(size);
    const transfer = offer?.transfer ?? null;

    return [
        {
            label: 'CPU',
            value: [size.vcpus, size.cpu_type, 'vCPU']
                .filter(Boolean)
                .join(' '),
        },
        ...(processor ? [{ label: 'Processor', value: processor }] : []),
        { label: 'Memory', value: formatMemory(size.memory) },
        {
            label: 'Disk',
            value: [`${size.disk} GB`, size.disk_type]
                .filter(Boolean)
                .join(' '),
        },
        ...(transfer !== null
            ? [
                  {
                      label: 'Traffic',
                      value: `${formatTransfer(transfer)} included`,
                  },
              ]
            : []),
    ];
}

const sizeColumns =
    'grid grid-cols-[1rem_minmax(0,1.4fr)_repeat(3,minmax(0,1fr))_minmax(0,1.2fr)] items-center gap-3 px-3';

const sizeColumnsWithTraffic =
    'grid grid-cols-[1rem_minmax(0,1.4fr)_repeat(4,minmax(0,1fr))_minmax(0,1.2fr)] items-center gap-3 px-3';

/**
 * The sizes listed in one region, grouped the way the provider groups them
 * (family, shared or dedicated vCPUs, processor) and priced for that region.
 * Sizes the provider can't create there right now are shown but can't be picked.
 */
function SizeTable({ sizes, region, selected, onSelect }: SizeTableProps) {
    if (sizes.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No sizes are available in this region.
            </p>
        );
    }

    // Sizes arrive cheapest first, so each group starts with its cheapest size.
    const groups = new Map<string, ProviderSize[]>();
    for (const option of sizes) {
        const group = describeSizeGroup(option);
        groups.set(group, [...(groups.get(group) ?? []), option]);
    }

    const showsTraffic = sizes.some(
        (option) => sizeOfferIn(option, region).transfer !== null,
    );
    const columns = showsTraffic ? sizeColumnsWithTraffic : sizeColumns;

    return (
        <div
            role="radiogroup"
            aria-labelledby="size-label"
            className="max-h-96 overflow-y-auto rounded-md border text-sm [&>div:last-child>button:last-child]:border-b-0"
        >
            <div
                className={cn(
                    columns,
                    'sticky top-0 z-10 border-b bg-card py-2 text-xs text-muted-foreground',
                )}
                aria-hidden
            >
                <span />
                <span>Name</span>
                <span>vCPU</span>
                <span>RAM</span>
                <span>Disk</span>
                {showsTraffic && <span>Traffic</span>}
                <span className="text-right">Price</span>
            </div>

            {[...groups.entries()].map(([group, options]) => (
                <div key={group}>
                    {group && (
                        <div className="border-b bg-muted/40 px-3 py-1.5 text-xs font-medium text-muted-foreground">
                            {group}
                        </div>
                    )}
                    {options.map((option) => {
                        const isSelected = option.slug === selected;
                        const isInStock = isSizeInStockIn(option, region);
                        const offer = sizeOfferIn(option, region);

                        return (
                            <button
                                key={option.slug}
                                type="button"
                                role="radio"
                                aria-checked={isSelected}
                                disabled={!isInStock}
                                onClick={() => onSelect(option.slug)}
                                className={cn(
                                    columns,
                                    'w-full border-b py-2 text-left transition-colors focus-visible:bg-muted focus-visible:outline-none disabled:text-muted-foreground',
                                    isSelected
                                        ? 'bg-muted/60'
                                        : 'enabled:hover:bg-muted/40',
                                )}
                            >
                                <RadioDot selected={isSelected} />
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">
                                        {option.label ?? option.slug}
                                    </span>
                                    {!isInStock && (
                                        <span className="block truncate text-xs">
                                            Unavailable
                                        </span>
                                    )}
                                </span>
                                <span>{option.vcpus}</span>
                                <span>{formatMemory(option.memory)}</span>
                                <span>{option.disk} GB</span>
                                {showsTraffic && (
                                    <span>
                                        {offer.transfer !== null
                                            ? formatTransfer(offer.transfer)
                                            : '—'}
                                    </span>
                                )}
                                <span className="text-right whitespace-nowrap">
                                    {offer.price_monthly > 0 ? (
                                        <>
                                            {formatPrice(
                                                offer.price_monthly,
                                                option.currency,
                                            )}
                                            <span className="text-muted-foreground">
                                                /mo
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                    {offer.price_hourly ? (
                                        <span className="block text-xs text-muted-foreground">
                                            {formatHourlyPrice(
                                                offer.price_hourly,
                                                option.currency,
                                            )}
                                            /hr
                                        </span>
                                    ) : null}
                                </span>
                            </button>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

/** The monthly cost lines for the chosen size, plus what they leave out. */
interface Costs {
    currency: string;
    lines: { label: string; amount: number }[];
    /** The server's own hourly price, when the provider quotes one. */
    hourly: number | null;
    /** Price per TB of traffic beyond the included amount, when the provider publishes it. */
    trafficPricePerTb: number | null;
    note: string;
    url: string;
}

/** The itemised monthly estimate shown in the summary. */
function CostBreakdown({ costs }: { costs: Costs }) {
    return (
        <div className="space-y-2 border-t pt-4 text-sm">
            {costs.lines.length > 1 &&
                costs.lines.map((line) => (
                    <div
                        key={line.label}
                        className="flex justify-between gap-4"
                    >
                        <span className="text-muted-foreground">
                            {line.label}
                        </span>
                        <span>
                            {formatPrice(line.amount, costs.currency)}
                            <span className="text-muted-foreground">/mo</span>
                        </span>
                    </div>
                ))}
            <div className="flex items-baseline justify-between gap-4">
                <span className="text-muted-foreground">Estimated total</span>
                <span>
                    <span className="font-semibold">
                        {formatPrice(
                            costs.lines.reduce(
                                (total, line) => total + line.amount,
                                0,
                            ),
                            costs.currency,
                        )}
                    </span>
                    <span className="text-muted-foreground">/mo</span>
                </span>
            </div>
            <p className="text-xs text-muted-foreground">
                {costs.hourly
                    ? `The server is billed by the hour at ${formatHourlyPrice(costs.hourly, costs.currency)}. `
                    : null}
                {costs.note}{' '}
                {costs.trafficPricePerTb
                    ? `Traffic beyond the included amount costs ${formatPrice(costs.trafficPricePerTb, costs.currency)} per TB. `
                    : null}
                <a
                    href={costs.url}
                    target="_blank"
                    rel="noreferrer"
                    className="underline underline-offset-2 hover:text-foreground"
                >
                    Pricing details
                </a>
            </p>
        </div>
    );
}

interface StackSectionProps {
    serverTypes: ServerTypeOption[];
    stack: Stack;
    onChange: (stack: Stack) => void;
    errors: Partial<Record<keyof Stack, string>>;
}

function StackSection({
    serverTypes,
    stack,
    onChange,
    errors,
}: StackSectionProps) {
    const update = (changes: Partial<Stack>) =>
        onChange({ ...stack, ...changes });

    // A web server needs PHP, so turning PHP off turns the web server off too.
    const handlePhpChange = (value: string) =>
        value === NONE
            ? update({ php_version: null, web_server: null })
            : update({ php_version: value });

    return (
        <FormSection
            title="Server type"
            description="Pick a starting point, then adjust what gets installed."
        >
            <div
                role="radiogroup"
                aria-label="Server type"
                className="grid gap-2 sm:grid-cols-2"
            >
                {serverTypes.map((option) => (
                    <RadioOption
                        key={option.value}
                        selected={stack.type === option.value}
                        onSelect={() => onChange(applyPreset(option, stack))}
                        title={option.label}
                        description={option.description}
                    />
                ))}
            </div>
            <InputError message={errors.type} />

            <div className="grid gap-4 pt-2 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="php_version">PHP</Label>
                    <Select
                        value={stack.php_version ?? NONE}
                        onValueChange={handlePhpChange}
                    >
                        <SelectTrigger id="php_version">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {phpVersions.map((version) => (
                                <SelectItem
                                    key={version.value}
                                    value={version.value}
                                >
                                    {version.label}
                                </SelectItem>
                            ))}
                            <SelectItem value={NONE}>None</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.php_version} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="web_server">Web server</Label>
                    <Select
                        value={stack.web_server ?? NONE}
                        onValueChange={(value) =>
                            update({
                                web_server: value === NONE ? null : value,
                            })
                        }
                        disabled={stack.php_version === null}
                    >
                        <SelectTrigger id="web_server">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {webServers.map((server) => (
                                <SelectItem
                                    key={server.value}
                                    value={server.value}
                                >
                                    {server.label}
                                </SelectItem>
                            ))}
                            <SelectItem value={NONE}>None</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.web_server} />
                    {stack.php_version === null && (
                        <p className="text-xs text-muted-foreground">
                            A web server needs PHP.
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="database_type">Database</Label>
                    <Select
                        value={stack.database_type ?? NONE}
                        onValueChange={(value) =>
                            update({
                                database_type: value === NONE ? null : value,
                            })
                        }
                    >
                        <SelectTrigger id="database_type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {databaseTypes.map((db) => (
                                <SelectItem key={db.value} value={db.value}>
                                    {db.label}
                                </SelectItem>
                            ))}
                            <SelectItem value={NONE}>None</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.database_type} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="redis">Redis</Label>
                    <div className="flex h-9 items-center gap-3">
                        <Switch
                            id="redis"
                            checked={stack.redis}
                            onCheckedChange={(checked) =>
                                update({ redis: checked })
                            }
                        />
                        <span className="text-sm text-muted-foreground">
                            For caching and queues
                        </span>
                    </div>
                    <InputError message={errors.redis} />
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                Always installed: {stack.php_version !== null && 'Composer, '}
                Node.js 24, Supervisor and the UFW firewall.
            </p>
        </FormSection>
    );
}
