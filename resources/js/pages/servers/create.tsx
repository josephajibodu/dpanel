import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, CloudIcon, Loader2Icon, ServerIcon } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { ProviderAccount } from '@/types/provider-account';
import { ProviderRegion, ProviderSize, ServerTypeOption, ServerTypeValue } from '@/types/server';

interface Props {
    providerAccounts: {
        data: ProviderAccount[];
    };
    regions: Record<number, ProviderRegion[]>;
    sizes: Record<number, ProviderSize[]>;
    generatedName: string;
    serverTypes: ServerTypeOption[];
}

type Mode = 'provider' | 'custom';

const phpVersions = [
    { value: '8.4', label: 'PHP 8.4' },
    { value: '8.3', label: 'PHP 8.3' },
    { value: '8.2', label: 'PHP 8.2' },
    { value: '8.1', label: 'PHP 8.1' },
];

const webServers = [{ value: 'nginx', label: 'Nginx' }];

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
        php_version: option.services.php ? (current.php_version ?? '8.3') : null,
        web_server: option.services.web_server ? (current.web_server ?? 'nginx') : null,
        database_type: option.services.database ? (current.database_type ?? 'mysql') : null,
        redis: option.services.redis,
    };
}

const defaultStack: Stack = {
    type: 'app',
    php_version: '8.3',
    web_server: 'nginx',
    database_type: 'mysql',
    redis: true,
};

export default function ServersCreate({ providerAccounts, regions, sizes, generatedName, serverTypes }: Props) {
    const teamPath = useTeamPath();
    const [mode, setMode] = useState<Mode>('provider');
    const [isGeneratingName, setIsGeneratingName] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: 'Create Server', href: teamPath('/servers/create') },
    ];

    const providerForm = useForm({
        name: generatedName ?? '',
        provider_account_id: '',
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
    const availableRegions = selectedAccountId ? regions[selectedAccountId] || [] : [];
    const availableSizes = selectedAccountId ? sizes[selectedAccountId] || [] : [];

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

    const handleProviderChange = (value: string) => {
        providerForm.setData({
            ...providerForm.data,
            provider_account_id: value,
            region: '',
            size: '',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Server" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={teamPath('/servers')}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Create Server</h1>
                        <p className="text-muted-foreground text-sm">Provision a new server with your preferred configuration.</p>
                    </div>
                </div>

                <div className="mx-auto w-full max-w-2xl space-y-6">
                    {/* Mode selector */}
                    <div className="grid grid-cols-2 gap-3">
                        <button
                            type="button"
                            onClick={() => setMode('provider')}
                            className={`flex items-center gap-3 rounded-lg border p-4 text-left transition-colors ${
                                mode === 'provider'
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border hover:border-primary/50 hover:bg-muted/50'
                            }`}
                        >
                            <CloudIcon className="h-5 w-5 shrink-0 text-primary" />
                            <div>
                                <p className="font-medium">New Server</p>
                                <p className="text-muted-foreground text-xs">Create via a provider</p>
                            </div>
                        </button>
                        <button
                            type="button"
                            onClick={() => setMode('custom')}
                            className={`flex items-center gap-3 rounded-lg border p-4 text-left transition-colors ${
                                mode === 'custom'
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border hover:border-primary/50 hover:bg-muted/50'
                            }`}
                        >
                            <ServerIcon className="h-5 w-5 shrink-0 text-primary" />
                            <div>
                                <p className="font-medium">Existing Server</p>
                                <p className="text-muted-foreground text-xs">Connect your own server</p>
                            </div>
                        </button>
                    </div>

                    {mode === 'provider' ? (
                        <form onSubmit={handleProviderSubmit} className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Server Details</CardTitle>
                                    <CardDescription>Configure the basic settings for your new server.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="name">Server Name</Label>
                                            <button
                                                type="button"
                                                onClick={handleGenerateName}
                                                disabled={isGeneratingName}
                                                className="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline disabled:opacity-50"
                                            >
                                                {isGeneratingName && <Loader2Icon className="h-3.5 w-3.5 animate-spin" />}
                                                Generate new name
                                            </button>
                                        </div>
                                        <Input
                                            id="name"
                                            type="text"
                                            value={providerForm.data.name}
                                            onChange={(e) => providerForm.setData('name', e.target.value)}
                                            placeholder="e.g., production-api"
                                        />
                                        <InputError message={providerForm.errors.name} />
                                        <p className="text-muted-foreground text-sm">
                                            Only letters, numbers, and hyphens. This will be used as the server hostname.
                                        </p>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="provider_account_id">Provider Account</Label>
                                        <Select value={providerForm.data.provider_account_id} onValueChange={handleProviderChange}>
                                            <SelectTrigger id="provider_account_id">
                                                <SelectValue placeholder="Select a provider account" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {providerAccounts.data.map((account) => (
                                                    <SelectItem key={account.id} value={account.id.toString()}>
                                                        {account.name} ({account.provider_label})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={providerForm.errors.provider_account_id} />
                                        {providerAccounts.data.length === 0 && (
                                            <p className="text-muted-foreground text-sm">
                                                No provider accounts found.{' '}
                                                <Link href={teamPath('/provider-accounts/create')} className="text-primary underline">
                                                    Connect one first
                                                </Link>
                                                .
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid gap-6 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="region">Region</Label>
                                            <Select
                                                value={providerForm.data.region}
                                                onValueChange={(value) => providerForm.setData('region', value)}
                                                disabled={!selectedAccountId}
                                            >
                                                <SelectTrigger id="region">
                                                    <SelectValue placeholder="Select a region" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableRegions.map((region) => (
                                                        <SelectItem key={region.slug} value={region.slug}>
                                                            {region.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={providerForm.errors.region} />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="size">Server Size</Label>
                                            <Select
                                                value={providerForm.data.size}
                                                onValueChange={(value) => providerForm.setData('size', value)}
                                                disabled={!selectedAccountId}
                                            >
                                                <SelectTrigger id="size">
                                                    <SelectValue placeholder="Select a size" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableSizes.map((size) => (
                                                        <SelectItem key={size.slug} value={size.slug}>
                                                            {size.description}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={providerForm.errors.size} />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <StackConfigCard
                                serverTypes={serverTypes}
                                stack={providerForm.data}
                                onChange={(stack) => providerForm.setData({ ...providerForm.data, ...stack })}
                                errors={providerForm.errors}
                            />

                            <div className="flex justify-end gap-3">
                                <Button variant="outline" asChild>
                                    <Link href={teamPath('/servers')}>Cancel</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={providerForm.processing || providerAccounts.data.length === 0}
                                >
                                    {providerForm.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                    Create Server
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <form onSubmit={handleCustomSubmit} className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Server Details</CardTitle>
                                    <CardDescription>
                                        Enter your server details. You'll be shown how to connect FlitOps on the next step.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="custom-name">Server Name</Label>
                                            <button
                                                type="button"
                                                onClick={handleGenerateName}
                                                disabled={isGeneratingName}
                                                className="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline disabled:opacity-50"
                                            >
                                                {isGeneratingName && <Loader2Icon className="h-3.5 w-3.5 animate-spin" />}
                                                Generate new name
                                            </button>
                                        </div>
                                        <Input
                                            id="custom-name"
                                            type="text"
                                            value={customForm.data.name}
                                            onChange={(e) => customForm.setData('name', e.target.value)}
                                            placeholder="e.g., production-api"
                                        />
                                        <InputError message={customForm.errors.name} />
                                        <p className="text-muted-foreground text-sm">
                                            Only letters, numbers, and hyphens. This will be used as the server hostname.
                                        </p>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="ip_address">
                                            IP address <span className="text-destructive">*</span>
                                        </Label>
                                        <Input
                                            id="ip_address"
                                            type="text"
                                            value={customForm.data.ip_address}
                                            onChange={(e) => customForm.setData('ip_address', e.target.value)}
                                            placeholder="Enter a public IPv4 or IPv6 address"
                                        />
                                        <InputError message={customForm.errors.ip_address} />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="ssh_port">SSH port</Label>
                                        <Input
                                            id="ssh_port"
                                            type="number"
                                            min={1}
                                            max={65535}
                                            value={customForm.data.ssh_port}
                                            onChange={(e) => customForm.setData('ssh_port', e.target.value)}
                                        />
                                        <InputError message={customForm.errors.ssh_port} />
                                    </div>
                                </CardContent>
                            </Card>

                            <StackConfigCard
                                serverTypes={serverTypes}
                                stack={customForm.data}
                                onChange={(stack) => customForm.setData({ ...customForm.data, ...stack })}
                                errors={customForm.errors}
                            />

                            <div className="flex justify-end gap-3">
                                <Button variant="outline" asChild>
                                    <Link href={teamPath('/servers')}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={customForm.processing}>
                                    {customForm.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                    Connect & Provision
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

interface StackConfigCardProps {
    serverTypes: ServerTypeOption[];
    stack: Stack;
    onChange: (stack: Stack) => void;
    errors: Partial<Record<keyof Stack, string>>;
}

function StackConfigCard({ serverTypes, stack, onChange, errors }: StackConfigCardProps) {
    const update = (changes: Partial<Stack>) => onChange({ ...stack, ...changes });

    // A web server needs PHP, so turning PHP off turns the web server off too.
    const handlePhpChange = (value: string) =>
        value === NONE ? update({ php_version: null, web_server: null }) : update({ php_version: value });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Server Type</CardTitle>
                <CardDescription>
                    Pick a starting point, then adjust which services get installed.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <div className="grid gap-3 sm:grid-cols-2">
                    {serverTypes.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => onChange(applyPreset(option, stack))}
                            className={`rounded-lg border p-4 text-left transition-colors ${
                                stack.type === option.value
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border hover:border-primary/50 hover:bg-muted/50'
                            }`}
                        >
                            <p className="font-medium">{option.label}</p>
                            <p className="text-muted-foreground text-xs">{option.description}</p>
                        </button>
                    ))}
                </div>
                <InputError message={errors.type} />

                <div className="grid gap-6 md:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="php_version">PHP</Label>
                        <Select value={stack.php_version ?? NONE} onValueChange={handlePhpChange}>
                            <SelectTrigger id="php_version">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {phpVersions.map((version) => (
                                    <SelectItem key={version.value} value={version.value}>
                                        {version.label}
                                    </SelectItem>
                                ))}
                                <SelectItem value={NONE}>None</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.php_version} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="web_server">Web Server</Label>
                        <Select
                            value={stack.web_server ?? NONE}
                            onValueChange={(value) => update({ web_server: value === NONE ? null : value })}
                            disabled={stack.php_version === null}
                        >
                            <SelectTrigger id="web_server">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {webServers.map((server) => (
                                    <SelectItem key={server.value} value={server.value}>
                                        {server.label}
                                    </SelectItem>
                                ))}
                                <SelectItem value={NONE}>None</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.web_server} />
                        {stack.php_version === null && (
                            <p className="text-muted-foreground text-sm">A web server needs PHP.</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="database_type">Database</Label>
                        <Select
                            value={stack.database_type ?? NONE}
                            onValueChange={(value) => update({ database_type: value === NONE ? null : value })}
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
                            <Switch id="redis" checked={stack.redis} onCheckedChange={(checked) => update({ redis: checked })} />
                            <span className="text-muted-foreground text-sm">For caching and queues</span>
                        </div>
                        <InputError message={errors.redis} />
                    </div>
                </div>

                <div className="text-muted-foreground rounded-lg border bg-muted/50 p-4 text-sm">
                    <p className="font-medium">Always installed:</p>
                    <ul className="mt-2 list-inside list-disc space-y-1">
                        {stack.php_version !== null && <li>Composer</li>}
                        <li>Node.js 24</li>
                        <li>Supervisor for background processes</li>
                        <li>Firewall (UFW)</li>
                    </ul>
                </div>
            </CardContent>
        </Card>
    );
}
