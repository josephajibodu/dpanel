import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, GlobeIcon, Loader2Icon, PackageIcon } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { RepositorySelector } from '@/components/sites/repository-selector';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { PhpVersion, ProjectType, RepositoryProvider } from '@/types/site';
import { SourceControlAccount } from '@/types/source-control';

interface Repository {
    id: number;
    name: string;
    full_name: string;
    ssh_url: string;
    html_url: string;
    default_branch: string;
    private: boolean;
}

interface SourceControlData {
    accounts: {
        data: SourceControlAccount[];
    };
}

interface Branch {
    name: string;
    protected: boolean;
}

interface Props {
    server: {
        data: Server;
    };
    projectTypes: ProjectType[];
    repositoryProviders: RepositoryProvider[];
    phpVersions: PhpVersion[];
    sourceControl?: SourceControlData;
}

const PACKAGE_MANAGERS = [
    { value: 'none', label: 'None' },
    { value: 'npm', label: 'npm' },
    { value: 'pnpm', label: 'pnpm' },
    { value: 'yarn', label: 'yarn' },
    { value: 'bun', label: 'bun' },
] as const;

const DEFAULT_BUILD_COMMANDS: Record<string, string> = {
    none: '',
    npm: 'npm run build',
    pnpm: 'pnpm run build',
    yarn: 'yarn build',
    bun: 'bun run build',
};

export default function SitesCreate({ server, projectTypes, repositoryProviders, phpVersions, sourceControl }: Props) {
    const { data: serverData } = server;
    const [branches, setBranches] = useState<Branch[]>([]);
    const [loadingBranches, setLoadingBranches] = useState(false);
    const [repositories, setRepositories] = useState<Repository[]>([]);
    const [loadingRepositories, setLoadingRepositories] = useState(false);
    const [useCustomDomain, setUseCustomDomain] = useState(false);

    const firstAccountId = sourceControl?.accounts.data[0]?.id;

    const form = useForm({
        server_id: serverData.id,
        domain: '',
        site_name: '',
        directory: '/public',
        repository: '',
        repository_provider: sourceControl?.accounts.data[0]?.provider ?? 'github',
        branch: 'main',
        project_type: 'laravel',
        php_version: serverData.php_version || phpVersions[0]?.value || '8.3',
        package_manager: 'npm',
        build_command: 'npm run build',
        auto_deploy: false,
        source_control_account_id: firstAccountId ?? undefined,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: serverData.name, href: `/servers/${serverData.id}` },
        { title: 'New Site', href: `/servers/${serverData.id}/sites/create` },
    ];

    const fetchRepositories = useCallback(() => {
        if (!form.data.source_control_account_id) {
            setRepositories([]);
            setLoadingRepositories(false);
            return;
        }

        setLoadingRepositories(true);
        const accountId = form.data.source_control_account_id;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const url = `/source-control/${accountId}/repositories`;

        fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) {
                    return res.text().then((text) => {
                        throw new Error(`HTTP ${res.status}: ${text}`);
                    });
                }
                return res.json();
            })
            .then((data) => {
                setRepositories(data.repositories || []);
            })
            .catch(() => {
                setRepositories([]);
            })
            .finally(() => {
                setLoadingRepositories(false);
            });
    }, [form.data.source_control_account_id]);

    useEffect(() => {
        fetchRepositories();
    }, [fetchRepositories]);

    // Fetch branches when repository is selected
    useEffect(() => {
        // Reset branches when repository or account changes
        if (!form.data.repository || !form.data.source_control_account_id) {
            setBranches([]);
            setLoadingBranches(false);
            return;
        }

        setLoadingBranches(true);
        const accountId = form.data.source_control_account_id;
        const account = sourceControl?.accounts.data.find((a) => a.id === accountId);

        if (!account) {
            setLoadingBranches(false);
            return;
        }

        // Get CSRF token from meta tag
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const url = `/source-control/${accountId}/repositories/${encodeURIComponent(form.data.repository)}/branches`;

        fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) {
                    return res.text().then((text) => {
                        console.error(`Failed to fetch branches: HTTP ${res.status}`, text);
                        throw new Error(`HTTP ${res.status}: ${text}`);
                    });
                }
                return res.json();
            })
            .then((data) => {
                const branchesList = data.branches || [];
                console.log('Fetched branches:', branchesList);
                setBranches(branchesList);

                // Auto-select default branch if available and branch is not already set
                if (branchesList.length > 0) {
                    const currentBranch = form.data.branch;
                    const branchExists = branchesList.some((b: Branch) => b.name === currentBranch);

                    // Only auto-select if current branch doesn't exist in the list
                    if (!branchExists) {
                        const defaultBranch = branchesList.find((b: Branch) => b.name === 'main')
                            || branchesList.find((b: Branch) => b.name === 'master')
                            || branchesList[0];
                        if (defaultBranch) {
                            form.setData('branch', defaultBranch.name);
                        }
                    }
                }
            })
            .catch((error) => {
                console.error('Failed to fetch branches:', error);
                setBranches([]);
            })
            .finally(() => {
                setLoadingBranches(false);
            });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.repository, form.data.source_control_account_id, sourceControl?.accounts.data]);

    // Auto-fill build command when package manager changes
    useEffect(() => {
        if (form.data.package_manager && form.data.package_manager !== 'none') {
            const defaultCommand = DEFAULT_BUILD_COMMANDS[form.data.package_manager];
            if (defaultCommand && !form.data.build_command) {
                form.setData('build_command', defaultCommand);
            }
        } else if (form.data.package_manager === 'none') {
            form.setData('build_command', '');
        }
    }, [form.data.package_manager]);

    function handleProjectTypeChange(value: string) {
        const projectType = projectTypes.find((pt) => pt.value === value);
        form.setData({
            ...form.data,
            project_type: value,
            directory: projectType?.defaultDirectory || '/public',
        });
    }

    function handlePackageManagerChange(value: string) {
        form.setData({
            ...form.data,
            package_manager: value,
            build_command: value !== 'none' ? DEFAULT_BUILD_COMMANDS[value] || '' : '',
        });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/servers/${serverData.id}/sites`);
    }

    const serverIpFormatted = serverData.ip_address?.replace(/\./g, '-') || '';
    const nipIoDomain = form.data.site_name ? `${form.data.site_name}.${serverIpFormatted}.nip.io` : '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`New Site - ${serverData.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={`/servers/${serverData.id}`}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">New Site</h1>
                        <p className="text-muted-foreground text-sm">Create a new site on {serverData.name}</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    <div className="mx-auto grid max-w-3xl gap-6">
                        {/* Domain Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <GlobeIcon className="h-5 w-5" />
                                        Domain Configuration
                                    </div>
                                    {!useCustomDomain && (
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="h-auto p-0 text-primary"
                                            onClick={() => setUseCustomDomain(true)}
                                        >
                                            Use custom domain
                                        </Button>
                                    )}
                                </CardTitle>
                                <CardDescription>
                                    {useCustomDomain
                                        ? 'Configure a custom domain for your site.'
                                        : 'Each site includes a nip.io domain that can be disabled later.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {useCustomDomain ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="domain">Domain Name</Label>
                                        <Input
                                            id="domain"
                                            placeholder="example.com"
                                            value={form.data.domain}
                                            onChange={(e) => form.setData('domain', e.target.value)}
                                            className={form.errors.domain ? 'border-destructive' : ''}
                                        />
                                        {form.errors.domain && <p className="text-destructive text-sm">{form.errors.domain}</p>}
                                        <p className="text-muted-foreground text-xs">
                                            Your site will be served from{' '}
                                            <code className="bg-muted rounded px-1">
                                                /home/artisan/{form.data.domain || 'example.com'}
                                            </code>
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <Label htmlFor="site_name">Site Name</Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                id="site_name"
                                                placeholder="react-blog"
                                                value={form.data.site_name}
                                                onChange={(e) => form.setData('site_name', e.target.value)}
                                                className={form.errors.site_name ? 'border-destructive' : ''}
                                            />
                                            <span className="text-muted-foreground whitespace-nowrap text-sm">.nip.io</span>
                                        </div>
                                        {form.errors.site_name && <p className="text-destructive text-sm">{form.errors.site_name}</p>}
                                        <p className="text-muted-foreground text-xs">
                                            Your site will be accessible at{' '}
                                            {nipIoDomain ? (
                                                <code className="bg-muted rounded px-1">{nipIoDomain}</code>
                                            ) : (
                                                <span className="italic">Enter a site name above</span>
                                            )}
                                        </p>
                                    </div>
                                )}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="project_type">Project Type</Label>
                                        <Select value={form.data.project_type} onValueChange={handleProjectTypeChange}>
                                            <SelectTrigger id="project_type">
                                                <SelectValue placeholder="Select project type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {projectTypes.map((type) => (
                                                    <SelectItem key={type.value} value={type.value}>
                                                        {type.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="directory">Web Directory</Label>
                                        <Input
                                            id="directory"
                                            placeholder="/public"
                                            value={form.data.directory}
                                            onChange={(e) => form.setData('directory', e.target.value)}
                                            className={form.errors.directory ? 'border-destructive' : ''}
                                        />
                                        {form.errors.directory && <p className="text-destructive text-sm">{form.errors.directory}</p>}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="php_version">PHP Version</Label>
                                    <Select value={form.data.php_version} onValueChange={(value) => form.setData('php_version', value)}>
                                        <SelectTrigger id="php_version">
                                            <SelectValue placeholder="Select PHP version" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {phpVersions.map((version) => (
                                                <SelectItem key={version.value} value={version.value}>
                                                    {version.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Source Control Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Source Control (Optional)</CardTitle>
                                <CardDescription>Connect a Git repository to enable deployments.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {sourceControl && sourceControl.accounts.data.length > 0 ? (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="source_control_account_id">Source Control Account</Label>
                                                <Select
                                                    value={form.data.source_control_account_id ? String(form.data.source_control_account_id) : ''}
                                                    onValueChange={(value) => {
                                                        const accountId = Number(value);
                                                        const account = sourceControl.accounts.data.find((a) => a.id === accountId);

                                                        form.setData({
                                                            ...form.data,
                                                            source_control_account_id: accountId,
                                                            repository_provider: account?.provider ?? form.data.repository_provider,
                                                            repository: '',
                                                            branch: 'main',
                                                        });
                                                        setBranches([]);
                                                        setRepositories([]);
                                                    }}
                                                >
                                                    <SelectTrigger id="source_control_account_id">
                                                        <SelectValue placeholder="Select a connected account" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {sourceControl.accounts.data.map((account) => (
                                                            <SelectItem key={account.id} value={String(account.id)}>
                                                                {account.provider_label} · @{account.provider_username}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            {form.data.source_control_account_id && (
                                                <div className="space-y-2">
                                                    <Label htmlFor="repository">Repository</Label>
                                                    <RepositorySelector
                                                        repositories={repositories}
                                                        loadingRepositories={loadingRepositories}
                                                        onReload={fetchRepositories}
                                                        value={form.data.repository}
                                                        onChange={(fullName, repo) => {
                                                            form.setData({
                                                                ...form.data,
                                                                repository: fullName,
                                                                branch: repo?.default_branch || form.data.branch,
                                                            });
                                                        }}
                                                        disabled={loadingRepositories}
                                                        placeholder="Select a repository"
                                                    />
                                                </div>
                                            )}
                                        </div>

                                        {form.data.repository && (
                                            <div className="space-y-2">
                                                <Label htmlFor="branch">Branch</Label>
                                                <Select
                                                    value={form.data.branch}
                                                    onValueChange={(value) => form.setData('branch', value)}
                                                    disabled={loadingBranches}
                                                >
                                                    <SelectTrigger id="branch" className={form.errors.branch ? 'border-destructive' : ''}>
                                                        <SelectValue placeholder={loadingBranches ? 'Loading branches...' : 'Select a branch'} />
                                                        {loadingBranches && (
                                                            <Loader2Icon className="ml-2 h-4 w-4 animate-spin text-muted-foreground" />
                                                        )}
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {loadingBranches ? (
                                                            <div className="flex items-center justify-center py-4 text-muted-foreground text-sm">
                                                                <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                                                Loading branches...
                                                            </div>
                                                        ) : branches.length > 0 ? (
                                                            branches.map((branch) => (
                                                                <SelectItem key={branch.name} value={branch.name}>
                                                                    {branch.name}
                                                                    {branch.protected && (
                                                                        <span className="text-muted-foreground ml-1 text-xs">(protected)</span>
                                                                    )}
                                                                </SelectItem>
                                                            ))
                                                        ) : (
                                                            // If no branches loaded but we have a branch value, show it as an option
                                                            form.data.branch ? (
                                                                <SelectItem value={form.data.branch}>{form.data.branch}</SelectItem>
                                                            ) : (
                                                                <div className="py-4 text-center text-muted-foreground text-sm">
                                                                    No branches available
                                                                </div>
                                                            )
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                {form.errors.branch && <p className="text-destructive text-sm">{form.errors.branch}</p>}
                                                {!loadingBranches && branches.length === 0 && form.data.branch && (
                                                    <p className="text-muted-foreground text-xs">
                                                        Using branch "{form.data.branch}". If this branch doesn't exist, you may need to enter it manually.
                                                    </p>
                                                )}
                                            </div>
                                        )}

                                        {form.data.source_control_account_id &&
                                            !loadingRepositories &&
                                            repositories.length === 0 && (
                                                <div className="rounded-lg border border-dashed p-4 text-center">
                                                    <p className="text-muted-foreground text-sm">No repositories found for this account.</p>
                                                </div>
                                            )}
                                    </>
                                ) : (
                                    <div className="space-y-4">
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="repository_provider">Provider</Label>
                                                <Select
                                                    value={form.data.repository_provider}
                                                    onValueChange={(value) => form.setData('repository_provider', value)}
                                                >
                                                    <SelectTrigger id="repository_provider">
                                                        <SelectValue placeholder="Select provider" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {repositoryProviders.map((provider) => (
                                                            <SelectItem key={provider.value} value={provider.value}>
                                                                {provider.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="repository">Repository</Label>
                                                <Input
                                                    id="repository"
                                                    placeholder="username/repository"
                                                    value={form.data.repository}
                                                    onChange={(e) => form.setData('repository', e.target.value)}
                                                    className={form.errors.repository ? 'border-destructive' : ''}
                                                />
                                                {form.errors.repository && <p className="text-destructive text-sm">{form.errors.repository}</p>}
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="branch">Branch</Label>
                                            <Input
                                                id="branch"
                                                placeholder="main"
                                                value={form.data.branch}
                                                onChange={(e) => form.setData('branch', e.target.value)}
                                                className={form.errors.branch ? 'border-destructive' : ''}
                                            />
                                            {form.errors.branch && <p className="text-destructive text-sm">{form.errors.branch}</p>}
                                        </div>

                                        <p className="text-muted-foreground text-xs">
                                            Connect a source control account to select from your repositories.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Frontend Build Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <PackageIcon className="h-5 w-5" />
                                    Frontend Build (Optional)
                                </CardTitle>
                                <CardDescription>Configure package manager and build command for frontend assets.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="package_manager">Package Manager</Label>
                                        <Select value={form.data.package_manager} onValueChange={handlePackageManagerChange}>
                                            <SelectTrigger id="package_manager">
                                                <SelectValue placeholder="Select package manager" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {PACKAGE_MANAGERS.map((pm) => (
                                                    <SelectItem key={pm.value} value={pm.value}>
                                                        {pm.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="build_command">Build Command</Label>
                                        <Input
                                            id="build_command"
                                            placeholder="npm run build"
                                            value={form.data.build_command}
                                            onChange={(e) => form.setData('build_command', e.target.value)}
                                            disabled={form.data.package_manager === 'none'}
                                            className={form.errors.build_command ? 'border-destructive' : ''}
                                        />
                                        {form.errors.build_command && (
                                            <p className="text-destructive text-sm">{form.errors.build_command}</p>
                                        )}
                                        {form.data.package_manager !== 'none' && (
                                            <p className="text-muted-foreground text-xs">
                                                This command will be run during deployments.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Submit */}
                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" asChild>
                                <Link href={`/servers/${serverData.id}`}>Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || (!form.data.domain && !form.data.site_name)}
                            >
                                {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                Create Site
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
