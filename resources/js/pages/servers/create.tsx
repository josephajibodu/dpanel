import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { ProviderAccount } from '@/types/provider-account';
import { ProviderRegion, ProviderSize } from '@/types/server';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, Loader2Icon, ServerIcon } from 'lucide-react';

interface Props {
    providerAccounts: {
        data: ProviderAccount[];
    };
    regions: Record<number, ProviderRegion[]>;
    sizes: Record<number, ProviderSize[]>;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Servers',
        href: '/servers',
    },
    {
        title: 'Create Server',
        href: '/servers/create',
    },
];

const phpVersions = [
    { value: '8.4', label: 'PHP 8.4' },
    { value: '8.3', label: 'PHP 8.3' },
    { value: '8.2', label: 'PHP 8.2' },
    { value: '8.1', label: 'PHP 8.1' },
];

const databaseTypes = [
    { value: 'mysql', label: 'MySQL 8' },
    { value: 'postgresql', label: 'PostgreSQL' },
    { value: 'mariadb', label: 'MariaDB' },
];

export default function ServersCreate({ providerAccounts, regions, sizes }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        provider_account_id: '',
        region: '',
        size: '',
        php_version: '8.3',
        database_type: 'mysql',
    });

    const selectedAccountId = data.provider_account_id ? parseInt(data.provider_account_id) : null;
    const availableRegions = selectedAccountId ? regions[selectedAccountId] || [] : [];
    const availableSizes = selectedAccountId ? sizes[selectedAccountId] || [] : [];

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/servers');
    };

    const handleProviderChange = (value: string) => {
        setData({
            ...data,
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
                        <Link href="/servers">
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Create Server</h1>
                        <p className="text-muted-foreground text-sm">Provision a new server with your preferred configuration.</p>
                    </div>
                </div>

                <div className="mx-auto w-full max-w-2xl">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <ServerIcon className="h-5 w-5" />
                                    Server Details
                                </CardTitle>
                                <CardDescription>Configure the basic settings for your new server.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Server Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., production-api"
                                    />
                                    <InputError message={errors.name} />
                                    <p className="text-muted-foreground text-sm">
                                        Only letters, numbers, and hyphens. This will be used as the server hostname.
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="provider_account_id">Provider Account</Label>
                                    <Select value={data.provider_account_id} onValueChange={handleProviderChange}>
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
                                    <InputError message={errors.provider_account_id} />
                                    {providerAccounts.data.length === 0 && (
                                        <p className="text-muted-foreground text-sm">
                                            No provider accounts found.{' '}
                                            <Link href="/provider-accounts/create" className="text-primary underline">
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
                                            value={data.region}
                                            onValueChange={(value) => setData('region', value)}
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
                                        <InputError message={errors.region} />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="size">Server Size</Label>
                                        <Select
                                            value={data.size}
                                            onValueChange={(value) => setData('size', value)}
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
                                        <InputError message={errors.size} />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Stack Configuration</CardTitle>
                                <CardDescription>Choose the software stack to install on your server.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-6 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="php_version">PHP Version</Label>
                                        <Select value={data.php_version} onValueChange={(value) => setData('php_version', value)}>
                                            <SelectTrigger id="php_version">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {phpVersions.map((version) => (
                                                    <SelectItem key={version.value} value={version.value}>
                                                        {version.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.php_version} />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="database_type">Database</Label>
                                        <Select value={data.database_type} onValueChange={(value) => setData('database_type', value)}>
                                            <SelectTrigger id="database_type">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {databaseTypes.map((db) => (
                                                    <SelectItem key={db.value} value={db.value}>
                                                        {db.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.database_type} />
                                    </div>
                                </div>

                                <div className="text-muted-foreground rounded-lg border bg-muted/50 p-4 text-sm">
                                    <p className="font-medium">The following will be installed:</p>
                                    <ul className="mt-2 list-inside list-disc space-y-1">
                                        <li>Nginx web server</li>
                                        <li>PHP {data.php_version} with common extensions</li>
                                        <li>{databaseTypes.find((d) => d.value === data.database_type)?.label}</li>
                                        <li>Redis cache server</li>
                                        <li>Composer & Node.js 20</li>
                                        <li>Supervisor for queue workers</li>
                                    </ul>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="flex justify-end gap-3">
                            <Button variant="outline" asChild>
                                <Link href="/servers">Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing || providerAccounts.data.length === 0}>
                                {processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                Create Server
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
