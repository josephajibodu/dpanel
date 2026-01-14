import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Provider } from '@/types/provider-account';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, CloudIcon, Loader2Icon } from 'lucide-react';

interface Props {
    providers: Provider[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Provider Accounts',
        href: '/provider-accounts',
    },
    {
        title: 'Connect Provider',
        href: '/provider-accounts/create',
    },
];

const providerInfo: Record<string, { description: string; tokenUrl: string; tokenLabel: string }> = {
    digitalocean: {
        description: 'DigitalOcean is a cloud infrastructure provider offering scalable compute platforms.',
        tokenUrl: 'https://cloud.digitalocean.com/account/api/tokens',
        tokenLabel: 'Personal Access Token',
    },
    hetzner: {
        description: 'Hetzner offers powerful dedicated servers and cloud solutions in Europe.',
        tokenUrl: 'https://console.hetzner.cloud/projects',
        tokenLabel: 'API Token',
    },
    vultr: {
        description: 'Vultr provides high performance cloud compute with global data centers.',
        tokenUrl: 'https://my.vultr.com/settings/#settingsapi',
        tokenLabel: 'API Key',
    },
};

export default function ProviderAccountsCreate({ providers }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        provider: '',
        name: '',
        api_token: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/provider-accounts');
    };

    const selectedProviderInfo = data.provider ? providerInfo[data.provider] : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connect Provider" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href="/provider-accounts">
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Connect Provider</h1>
                        <p className="text-muted-foreground text-sm">Add a new cloud provider account to provision servers.</p>
                    </div>
                </div>

                <div className="mx-auto w-full max-w-xl">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CloudIcon className="h-5 w-5" />
                                Provider Details
                            </CardTitle>
                            <CardDescription>Enter your cloud provider credentials to connect your account.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="provider">Cloud Provider</Label>
                                    <Select value={data.provider} onValueChange={(value) => setData('provider', value)}>
                                        <SelectTrigger id="provider">
                                            <SelectValue placeholder="Select a provider" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {providers.map((provider) => (
                                                <SelectItem key={provider.value} value={provider.value}>
                                                    {provider.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.provider} />
                                    {selectedProviderInfo && (
                                        <p className="text-muted-foreground text-sm">{selectedProviderInfo.description}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="name">Account Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Production Account"
                                    />
                                    <InputError message={errors.name} />
                                    <p className="text-muted-foreground text-sm">A friendly name to identify this account.</p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="api_token">{selectedProviderInfo?.tokenLabel || 'API Token'}</Label>
                                    <Input
                                        id="api_token"
                                        type="password"
                                        value={data.api_token}
                                        onChange={(e) => setData('api_token', e.target.value)}
                                        placeholder="Enter your API token"
                                    />
                                    <InputError message={errors.api_token} />
                                    {selectedProviderInfo && (
                                        <p className="text-muted-foreground text-sm">
                                            Get your token from{' '}
                                            <a
                                                href={selectedProviderInfo.tokenUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-primary underline underline-offset-4 hover:no-underline"
                                            >
                                                {data.provider === 'digitalocean'
                                                    ? 'DigitalOcean Dashboard'
                                                    : data.provider === 'hetzner'
                                                      ? 'Hetzner Console'
                                                      : 'Vultr Settings'}
                                            </a>
                                        </p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-3">
                                    <Button variant="outline" asChild>
                                        <Link href="/provider-accounts">Cancel</Link>
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                        Connect Provider
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
