import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, Loader2Icon } from 'lucide-react';

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
import { StorageProviderTypeOption } from '@/types/storage-provider';

interface Props {
    types: StorageProviderTypeOption[];
}

const typeInfo: Record<string, { description: string; helpUrl: string }> = {
    cloudflare_r2: {
        description:
            'Cloudflare R2 is S3-compatible object storage with no egress fees.',
        helpUrl: 'https://dash.cloudflare.com/?to=/:account/r2/api-tokens',
    },
    s3: {
        description: 'Amazon S3 object storage.',
        helpUrl:
            'https://console.aws.amazon.com/iam/home#/security_credentials',
    },
};

const fieldMeta: Record<
    string,
    { label: string; placeholder: string; secret?: boolean }
> = {
    account_id: {
        label: 'Cloudflare Account ID',
        placeholder: 'e.g., a1b2c3d4e5f6...',
    },
    access_key_id: {
        label: 'Access Key ID',
        placeholder: 'Enter your access key ID',
    },
    secret_access_key: {
        label: 'Secret Access Key',
        placeholder: 'Enter your secret access key',
        secret: true,
    },
    bucket: {
        label: 'Bucket Name',
        placeholder: 'e.g., flitops-backups',
    },
    region: {
        label: 'Region',
        placeholder: 'e.g., us-east-1',
    },
};

export default function StorageProvidersCreate({ types }: Props) {
    const teamPath = useTeamPath();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Storage Providers', href: teamPath('/storage-providers') },
        {
            title: 'Connect Storage',
            href: teamPath('/storage-providers/create'),
        },
    ];

    const { data, setData, post, processing, errors } = useForm({
        type: '',
        name: '',
        account_id: '',
        access_key_id: '',
        secret_access_key: '',
        bucket: '',
        region: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(teamPath('/storage-providers'));
    };

    const selectedType = types.find((t) => t.value === data.type);
    const selectedTypeInfo = data.type ? typeInfo[data.type] : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connect Storage" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={teamPath('/storage-providers')}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Connect Storage
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Add your own object storage account for backups and
                            other file storage.
                        </p>
                    </div>
                </div>

                <div className="mx-auto w-full max-w-xl">
                    <Card>
                        <CardHeader>
                            <CardTitle>Storage Details</CardTitle>
                            <CardDescription>
                                Enter your storage provider credentials to
                                connect your account.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="type">
                                        Storage Provider
                                    </Label>
                                    <Select
                                        value={data.type}
                                        onValueChange={(value) =>
                                            setData('type', value)
                                        }
                                    >
                                        <SelectTrigger id="type">
                                            <SelectValue placeholder="Select a storage provider" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {types.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.type} />
                                    {selectedTypeInfo && (
                                        <p className="text-sm text-muted-foreground">
                                            {selectedTypeInfo.description}
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
                                        placeholder="e.g., Production Backups"
                                    />
                                    <InputError message={errors.name} />
                                    <p className="text-sm text-muted-foreground">
                                        A friendly name to identify this
                                        account.
                                    </p>
                                </div>

                                {selectedType?.fields.map((field) => {
                                    const meta = fieldMeta[field];
                                    if (!meta) return null;

                                    return (
                                        <div key={field} className="space-y-2">
                                            <Label htmlFor={field}>
                                                {meta.label}
                                            </Label>
                                            <Input
                                                id={field}
                                                type={
                                                    meta.secret
                                                        ? 'password'
                                                        : 'text'
                                                }
                                                value={
                                                    data[
                                                        field as keyof typeof data
                                                    ]
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        field as keyof typeof data,
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={meta.placeholder}
                                                className="font-mono"
                                                autoComplete="off"
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        field as keyof typeof errors
                                                    ]
                                                }
                                            />
                                        </div>
                                    );
                                })}

                                {selectedTypeInfo && (
                                    <p className="text-sm text-muted-foreground">
                                        Get your credentials from{' '}
                                        <a
                                            href={selectedTypeInfo.helpUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary underline underline-offset-4 hover:no-underline"
                                        >
                                            {selectedType?.label} dashboard
                                        </a>
                                        .
                                    </p>
                                )}

                                <div className="flex justify-end gap-3">
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={teamPath(
                                                '/storage-providers',
                                            )}
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && (
                                            <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                        )}
                                        Connect Storage
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
