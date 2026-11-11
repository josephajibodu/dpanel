import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, CircleNotchIcon } from '@phosphor-icons/react';

import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Provider } from '@/types/provider-account';

interface Props {
    providers: Provider[];
}

const providerInfo: Record<
    string,
    {
        description: string;
        tokenUrl: string;
        tokenLabel: string;
        tokenSource: string;
    }
> = {
    digitalocean: {
        description:
            'DigitalOcean is a cloud infrastructure provider offering scalable compute platforms.',
        tokenUrl: 'https://cloud.digitalocean.com/account/api/tokens',
        tokenLabel: 'Personal Access Token',
        tokenSource: 'DigitalOcean Dashboard',
    },
    hetzner: {
        description:
            'Hetzner offers powerful dedicated servers and cloud solutions in Europe.',
        tokenUrl: 'https://console.hetzner.cloud/projects',
        tokenLabel: 'API Token',
        tokenSource: 'Hetzner Console',
    },
    vultr: {
        description:
            'Vultr provides high performance cloud compute with global data centers.',
        tokenUrl: 'https://my.vultr.com/settings/#settingsapi',
        tokenLabel: 'API Key',
        tokenSource: 'Vultr Settings',
    },
    aws: {
        description:
            'Amazon Web Services runs servers on EC2 in regions worldwide.',
        tokenUrl: 'https://console.aws.amazon.com/iam/home#/users',
        tokenLabel: 'Access Key',
        tokenSource: 'AWS IAM Console',
    },
};

/**
 * The least an IAM user needs for Flitops to create and delete EC2 servers,
 * and to read AWS's public price list so sizes show their real prices.
 */
const awsIamPolicy = JSON.stringify(
    {
        Version: '2012-10-17',
        Statement: [
            {
                Effect: 'Allow',
                Action: [
                    'ec2:DescribeRegions',
                    'ec2:DescribeImages',
                    'ec2:DescribeVpcs',
                    'ec2:DescribeSecurityGroups',
                    'ec2:CreateSecurityGroup',
                    'ec2:AuthorizeSecurityGroupIngress',
                    'ec2:ImportKeyPair',
                    'ec2:DeleteKeyPair',
                    'ec2:RunInstances',
                    'ec2:DescribeInstances',
                    'ec2:TerminateInstances',
                    'ec2:CreateTags',
                    'ec2:DescribeAddresses',
                    'ec2:AllocateAddress',
                    'ec2:AssociateAddress',
                    'ec2:DisassociateAddress',
                    'ec2:ReleaseAddress',
                    'pricing:GetProducts',
                ],
                Resource: '*',
            },
        ],
    },
    null,
    4,
);

export default function ProviderAccountsCreate({ providers }: Props) {
    const teamPath = useTeamPath();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Provider Accounts', href: teamPath('/provider-accounts') },
        {
            title: 'Connect Provider',
            href: teamPath('/provider-accounts/create'),
        },
    ];

    const { data, setData, post, processing, errors } = useForm({
        provider: '',
        name: '',
        api_token: '',
        access_key_id: '',
        secret_access_key: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(teamPath('/provider-accounts'));
    };

    const selectedProviderInfo = data.provider
        ? providerInfo[data.provider]
        : null;
    const isAws = data.provider === 'aws';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connect Provider" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={teamPath('/provider-accounts')}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Connect Provider
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Add a new cloud provider account to provision
                            servers.
                        </p>
                    </div>
                </div>

                <div className="mx-auto w-full max-w-xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Provider Details</CardTitle>
                            <CardDescription>
                                Enter your cloud provider credentials to connect
                                your account.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="provider">
                                        Cloud Provider
                                    </Label>
                                    <Select
                                        value={data.provider}
                                        onValueChange={(value) =>
                                            setData('provider', value)
                                        }
                                    >
                                        <SelectTrigger id="provider">
                                            <SelectValue placeholder="Select a provider" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {providers.map((provider) => (
                                                <SelectItem
                                                    key={provider.value}
                                                    value={provider.value}
                                                >
                                                    {provider.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.provider} />
                                    {selectedProviderInfo && (
                                        <p className="text-sm text-muted-foreground">
                                            {selectedProviderInfo.description}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="name">Account Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) =>
                                            setData('name', e.target.value)
                                        }
                                        placeholder="e.g., Production Account"
                                    />
                                    <InputError message={errors.name} />
                                    <p className="text-sm text-muted-foreground">
                                        A friendly name to identify this
                                        account.
                                    </p>
                                </div>

                                {isAws ? (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="access_key_id">
                                                Access Key ID
                                            </Label>
                                            <Input
                                                id="access_key_id"
                                                type="text"
                                                value={data.access_key_id}
                                                onChange={(e) =>
                                                    setData(
                                                        'access_key_id',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="AKIA..."
                                            />
                                            <InputError
                                                message={errors.access_key_id}
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="secret_access_key">
                                                Secret Access Key
                                            </Label>
                                            <Input
                                                id="secret_access_key"
                                                type="password"
                                                value={data.secret_access_key}
                                                onChange={(e) =>
                                                    setData(
                                                        'secret_access_key',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Enter your secret access key"
                                            />
                                            <InputError
                                                message={
                                                    errors.secret_access_key
                                                }
                                            />
                                            <p className="text-sm text-muted-foreground">
                                                Create an IAM user with the
                                                policy below and an access key
                                                in the{' '}
                                                <a
                                                    href={
                                                        providerInfo.aws
                                                            .tokenUrl
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-primary underline underline-offset-4 hover:no-underline"
                                                >
                                                    {
                                                        providerInfo.aws
                                                            .tokenSource
                                                    }
                                                </a>
                                                .
                                            </p>
                                        </div>
                                    </>
                                ) : (
                                    <div className="space-y-2">
                                        <Label htmlFor="api_token">
                                            {selectedProviderInfo?.tokenLabel ||
                                                'API Token'}
                                        </Label>
                                        <Input
                                            id="api_token"
                                            type="password"
                                            value={data.api_token}
                                            onChange={(e) =>
                                                setData(
                                                    'api_token',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Enter your API token"
                                        />
                                        <InputError
                                            message={errors.api_token}
                                        />
                                        {selectedProviderInfo && (
                                            <p className="text-sm text-muted-foreground">
                                                Get your token from{' '}
                                                <a
                                                    href={
                                                        selectedProviderInfo.tokenUrl
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-primary underline underline-offset-4 hover:no-underline"
                                                >
                                                    {
                                                        selectedProviderInfo.tokenSource
                                                    }
                                                </a>
                                            </p>
                                        )}
                                    </div>
                                )}

                                <div className="flex justify-end gap-3">
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={teamPath(
                                                '/provider-accounts',
                                            )}
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && (
                                            <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                        )}
                                        Connect Provider
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {isAws && (
                        <Card className="mt-6">
                            <CardHeader>
                                <CardTitle>IAM Policy</CardTitle>
                                <CardDescription>
                                    Attach this policy to the IAM user so
                                    Flitops can create, manage and delete EC2
                                    servers and read AWS's public price list,
                                    and nothing else.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="relative">
                                    <CopyButton
                                        value={awsIamPolicy}
                                        className="absolute top-2 right-2"
                                    />
                                    <pre className="max-h-80 overflow-auto rounded-md bg-muted p-4 text-xs">
                                        {awsIamPolicy}
                                    </pre>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
