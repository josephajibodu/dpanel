import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftIcon, DatabaseIcon, EyeIcon, EyeOffIcon, GlobeIcon, Loader2Icon, PackageIcon, PlusIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { RepositorySelector } from '@/components/sites/repository-selector';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Server, ServerDatabase } from '@/types/server';
import { PhpVersion, ProjectType } from '@/types/site';
import { SourceControlAccount } from '@/types/source-control';
import { toast } from 'sonner';

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
    freeDomain: string;
    projectTypes: ProjectType[];
    phpVersions: PhpVersion[];
    databases: { data: ServerDatabase[] };
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

const OAUTH_PROVIDERS = ['github', 'gitlab', 'bitbucket'] as const;
type OAuthProvider = (typeof OAUTH_PROVIDERS)[number];

const PROVIDER_LABELS: Record<OAuthProvider, string> = {
    github: 'GitHub',
    gitlab: 'GitLab',
    bitbucket: 'Bitbucket',
};

/**
 * Derive a valid subdomain label from a GitHub project name.
 * Lowercases, replaces runs of non-alphanumerics with a hyphen, and trims
 * leading/trailing hyphens so the result matches the site_name validation rule.
 */
function slugifyProjectName(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);
}

export default function SitesCreate({ server, freeDomain, projectTypes, phpVersions, databases, sourceControl }: Props) {
    const teamPath = useTeamPath();
    const { data: serverData } = server;
    const [branches, setBranches] = useState<Branch[]>([]);
    const [loadingBranches, setLoadingBranches] = useState(false);
    const [repositories, setRepositories] = useState<Repository[]>([]);
    const [loadingRepositories, setLoadingRepositories] = useState(false);
    const [useCustomDomain, setUseCustomDomain] = useState(false);
    const [connectDatabase, setConnectDatabase] = useState(false);
    const [createDbOpen, setCreateDbOpen] = useState(false);
    const [isCreatingDb, setIsCreatingDb] = useState(false);
    const [showDbPassword, setShowDbPassword] = useState(false);
    const [dbForm, setDbForm] = useState({ name: '', charset: '', collation: '', db_user: '', db_password: '' });
    const [providerPickerOpen, setProviderPickerOpen] = useState(false);
    const pendingDbNameRef = useRef<string | null>(null);

    const sourceControlAccounts = sourceControl?.accounts.data ?? [];

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
        server_database_id: undefined as number | undefined,
        package_manager: 'npm',
        build_command: 'npm run build',
        auto_deploy: false,
        source_control_account_id: firstAccountId ?? undefined,
    });

    const dbList = databases?.data ?? [];
    const readyDatabases = dbList.filter((db) => db.status === 'ready');
    const pendingDatabases = dbList.filter((db) => db.status === 'pending');

    useEffect(() => {
        if (!serverData.id || !window.Echo) {
            return;
        }

        const channel = window.Echo.private(`server.${serverData.id}`);

        channel.listen('.server.databases.updated', () => {
            router.reload({ only: ['databases'], preserveScroll: true });
        });

        return () => {
            channel.stopListening('.server.databases.updated');
            window.Echo.leave(`server.${serverData.id}`);
        };
    }, [serverData.id]);

    useEffect(() => {
        if (pendingDbNameRef.current) {
            const newDb = readyDatabases.find((db) => db.name === pendingDbNameRef.current);
            if (newDb) {
                form.setData('server_database_id', newDb.id);
                pendingDbNameRef.current = null;
            }
        }
    }, [readyDatabases]);

    const generatePassword = () => {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        const array = new Uint32Array(24);
        crypto.getRandomValues(array);
        for (let i = 0; i < 24; i++) {
            password += chars[array[i] % chars.length];
        }
        return password;
    };

    function handleCreateDatabase(e: React.FormEvent) {
        e.preventDefault();
        setIsCreatingDb(true);
        pendingDbNameRef.current = dbForm.name;
        router.post(
            teamPath(`/servers/${serverData.id}/databases`),
            {
                name: dbForm.name,
                charset: dbForm.charset || undefined,
                collation: dbForm.collation || undefined,
                db_user: dbForm.db_user || undefined,
                db_password: dbForm.db_password || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setCreateDbOpen(false);
                    setDbForm({ name: '', charset: '', collation: '', db_user: '', db_password: '' });
                    setShowDbPassword(false);
                },
                onFinish: () => setIsCreatingDb(false),
            },
        );
    }

    function handleDatabaseToggle(checked: boolean) {
        setConnectDatabase(checked);
        if (!checked) {
            form.setData('server_database_id', undefined);
        }
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: serverData.name, href: teamPath(`/servers/${serverData.id}`) },
        { title: 'New Site', href: teamPath(`/servers/${serverData.id}/sites/create`) },
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

    const applyRepository = (fullName: string, repo?: { name: string; default_branch: string }) => {
        const projectName = repo?.name ?? fullName.split('/')[1] ?? '';

        form.setData({
            ...form.data,
            repository: fullName,
            branch: repo?.default_branch || form.data.branch,
            site_name: slugifyProjectName(projectName),
        });
    };

    const openProviderPopup = (provider: OAuthProvider) => {
        const returnUrl = window.location.pathname + window.location.search;
        const url = `/auth/${provider}/redirect?popup=1&redirect=${encodeURIComponent(returnUrl)}`;
        const width = 600;
        const height = 700;
        const left = Math.max(0, window.screenX + (window.outerWidth - width) / 2);
        const top = Math.max(0, window.screenY + (window.outerHeight - height) / 2);
        const popup = window.open(url, 'source-control-oauth', `width=${width},height=${height},left=${left},top=${top}`);

        if (!popup) {
            toast.error('Please allow popups to connect a source control provider.');
        }
    };

    useEffect(() => {
        const previousIds = new Set(sourceControlAccounts.map((a) => a.id));

        function handleMessage(event: MessageEvent) {
            if (event.origin !== window.location.origin) return;
            const data = event.data as { type?: string; status?: string; error?: string } | undefined;
            if (data?.type !== 'flitops:source-control') return;

            if (data.status === 'connected') {
                router.reload({
                    only: ['sourceControl'],
                    onSuccess: (page) => {
                        const newAccounts =
                            (page.props.sourceControl as SourceControlData | undefined)?.accounts.data ?? [];
                        const added = newAccounts.find((a) => !previousIds.has(a.id));
                        if (added) {
                            form.setData({
                                ...form.data,
                                source_control_account_id: added.id,
                                repository_provider: added.provider,
                                repository: '',
                                branch: 'main',
                                site_name: '',
                            });
                        }
                    },
                });
            } else if (data.status === 'failed') {
                toast.error(data.error ?? 'Failed to connect source control provider.');
            }
        }

        window.addEventListener('message', handleMessage);
        return () => window.removeEventListener('message', handleMessage);
    }, [sourceControlAccounts, form]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        // Send only the field relevant to the chosen mode so the backend's
        // required_without rule resolves cleanly (site_name is always derived
        // from the selected repository, even when a custom domain is in use).
        form.transform((data) => ({
            ...data,
            domain: useCustomDomain ? data.domain : '',
            site_name: useCustomDomain ? '' : data.site_name,
        }));

        form.post(teamPath(`/servers/${serverData.id}/sites`));
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`New Site - ${serverData.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={teamPath(`/servers/${serverData.id}`)}>
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
                        {/* Source Control Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Source Control (Optional)</CardTitle>
                                <CardDescription>Connect a Git repository to enable deployments.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="source_control_account_id">Source control provider</Label>
                                        <Select
                                            value={form.data.source_control_account_id ? String(form.data.source_control_account_id) : ''}
                                            onValueChange={(value) => {
                                                if (value === '__add__') {
                                                    setProviderPickerOpen(true);
                                                    return;
                                                }
                                                const accountId = Number(value);
                                                const account = sourceControlAccounts.find((a) => a.id === accountId);

                                                form.setData({
                                                    ...form.data,
                                                    source_control_account_id: accountId,
                                                    repository_provider: account?.provider ?? form.data.repository_provider,
                                                    repository: '',
                                                    branch: 'main',
                                                    site_name: '',
                                                });
                                                setBranches([]);
                                                setRepositories([]);
                                            }}
                                        >
                                            <SelectTrigger id="source_control_account_id">
                                                <SelectValue
                                                    placeholder={
                                                        sourceControlAccounts.length === 0
                                                            ? 'No providers connected'
                                                            : 'Select a connected account'
                                                    }
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {sourceControlAccounts.map((account) => (
                                                    <SelectItem key={account.id} value={String(account.id)}>
                                                        {account.provider_label} · @{account.provider_username}
                                                    </SelectItem>
                                                ))}
                                                {sourceControlAccounts.length > 0 && <SelectSeparator />}
                                                <SelectItem value="__add__" className="text-primary">
                                                    <span className="flex items-center gap-2">
                                                        <PlusIcon className="h-4 w-4" />
                                                        Add source control provider
                                                    </span>
                                                </SelectItem>
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
                                                onChange={(fullName, repo) => applyRepository(fullName, repo)}
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

                                {sourceControlAccounts.length === 0 && (
                                    <p className="text-muted-foreground text-xs">
                                        Connect a source control provider to deploy from your repositories.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

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
                                        : `Each site includes a free ${freeDomain} subdomain.`}
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
                                        <Label>Free domain</Label>
                                        <div className="border-input bg-muted/40 flex items-center rounded-md border px-3 py-2 text-sm">
                                            {form.data.site_name ? (
                                                <span>{form.data.site_name}</span>
                                            ) : (
                                                <span className="text-muted-foreground italic">
                                                    Select a repository to generate a domain
                                                </span>
                                            )}
                                            <span className="text-muted-foreground ml-auto whitespace-nowrap">.{freeDomain}</span>
                                        </div>
                                        {form.errors.site_name && <p className="text-destructive text-sm">{form.errors.site_name}</p>}
                                        <p className="text-muted-foreground text-xs">
                                            Generated from your repository name. Use a custom domain to set your own.
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

                        {/* Database Connection */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <DatabaseIcon className="h-5 w-5" />
                                            Connect to Database
                                        </CardTitle>
                                        <CardDescription>Select or create a database to connect to your site.</CardDescription>
                                    </div>
                                    <Switch checked={connectDatabase} onCheckedChange={handleDatabaseToggle} />
                                </div>
                            </CardHeader>
                            {connectDatabase && (
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="server_database_id">Database</Label>
                                        <Select
                                            value={form.data.server_database_id ? String(form.data.server_database_id) : ''}
                                            onValueChange={(value) => {
                                                if (value === '__create__') {
                                                    setCreateDbOpen(true);
                                                    return;
                                                }
                                                form.setData('server_database_id', Number(value));
                                            }}
                                        >
                                            <SelectTrigger id="server_database_id" className={form.errors.server_database_id ? 'border-destructive' : ''}>
                                                <SelectValue placeholder="Select a database" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {readyDatabases.map((db) => (
                                                    <SelectItem key={db.id} value={String(db.id)}>
                                                        {db.name}
                                                    </SelectItem>
                                                ))}
                                                {pendingDatabases.map((db) => (
                                                    <SelectItem key={db.id} value={String(db.id)} disabled>
                                                        <span className="flex items-center gap-2">
                                                            <Loader2Icon className="h-3 w-3 animate-spin" />
                                                            {db.name}
                                                            <span className="text-muted-foreground text-xs">(creating…)</span>
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                                <SelectItem value="__create__">
                                                    <span className="flex items-center gap-1 text-primary">
                                                        <PlusIcon className="h-3.5 w-3.5" />
                                                        Create database
                                                    </span>
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {form.errors.server_database_id && (
                                            <p className="text-destructive text-sm">{form.errors.server_database_id}</p>
                                        )}
                                    </div>
                                    {pendingDatabases.length > 0 && (
                                        <div className="flex items-center gap-2 rounded-lg border border-dashed p-3">
                                            <Loader2Icon className="h-4 w-4 shrink-0 animate-spin text-primary" />
                                            <p className="text-muted-foreground text-sm">
                                                {pendingDatabases.length === 1
                                                    ? `Database "${pendingDatabases[0].name}" is being created…`
                                                    : `${pendingDatabases.length} databases are being created…`}
                                            </p>
                                        </div>
                                    )}
                                    {readyDatabases.length === 0 && pendingDatabases.length === 0 && (
                                        <div className="rounded-lg border border-dashed p-4 text-center">
                                            <p className="text-muted-foreground text-sm">No databases available on this server.</p>
                                            <Button type="button" variant="link" className="mt-1 h-auto p-0" onClick={() => setCreateDbOpen(true)}>
                                                Create one now
                                            </Button>
                                        </div>
                                    )}
                                </CardContent>
                            )}
                        </Card>

                        {/* Submit */}
                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" asChild>
                                <Link href={teamPath(`/servers/${serverData.id}`)}>Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || (useCustomDomain ? !form.data.domain : !form.data.site_name) || (connectDatabase && !form.data.server_database_id)}
                            >
                                {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                Create Site
                            </Button>
                        </div>
                    </div>
                </form>

                {/* Add Source Control Provider Dialog */}
                <Dialog open={providerPickerOpen} onOpenChange={setProviderPickerOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Add source control provider</DialogTitle>
                            <DialogDescription>
                                A new window will open to authorize the provider. Your form values stay on this page.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            {OAUTH_PROVIDERS.map((provider) => (
                                <Button
                                    key={provider}
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setProviderPickerOpen(false);
                                        openProviderPopup(provider);
                                    }}
                                >
                                    Continue with {PROVIDER_LABELS[provider]}
                                </Button>
                            ))}
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Create Database Sheet */}
                <Sheet open={createDbOpen} onOpenChange={setCreateDbOpen}>
                    <SheetContent side="right">
                        <SheetHeader>
                            <SheetTitle>Create database</SheetTitle>
                        </SheetHeader>
                        <form onSubmit={handleCreateDatabase} className="flex flex-1 flex-col gap-4 p-4 pt-4">
                            <div className="space-y-2">
                                <Label htmlFor="new-db-name">Name</Label>
                                <Input
                                    id="new-db-name"
                                    value={dbForm.name}
                                    onChange={(e) => setDbForm((p) => ({ ...p, name: e.target.value }))}
                                    placeholder="mydb"
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="new-db-charset">Charset (optional)</Label>
                                <Input
                                    id="new-db-charset"
                                    value={dbForm.charset}
                                    onChange={(e) => setDbForm((p) => ({ ...p, charset: e.target.value }))}
                                    placeholder="utf8mb4"
                                    className="font-mono"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="new-db-collation">Collation (optional)</Label>
                                <Input
                                    id="new-db-collation"
                                    value={dbForm.collation}
                                    onChange={(e) => setDbForm((p) => ({ ...p, collation: e.target.value }))}
                                    placeholder="utf8mb4_unicode_ci"
                                    className="font-mono"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="new-db-user">Database user (optional)</Label>
                                <Input
                                    id="new-db-user"
                                    value={dbForm.db_user}
                                    onChange={(e) => setDbForm((p) => ({ ...p, db_user: e.target.value }))}
                                    placeholder="Leave blank for default user"
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="new-db-password">Password</Label>
                                    <button
                                        type="button"
                                        className="text-primary text-sm font-medium hover:underline"
                                        onClick={() => {
                                            const pw = generatePassword();
                                            setDbForm((p) => ({ ...p, db_password: pw }));
                                            setShowDbPassword(true);
                                        }}
                                    >
                                        Generate password
                                    </button>
                                </div>
                                <div className="relative">
                                    <Input
                                        id="new-db-password"
                                        type={showDbPassword ? 'text' : 'password'}
                                        value={dbForm.db_password}
                                        onChange={(e) => setDbForm((p) => ({ ...p, db_password: e.target.value }))}
                                        placeholder="••••••••"
                                        autoComplete="new-password"
                                        className="pr-10"
                                    />
                                    <button
                                        type="button"
                                        className="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2"
                                        onClick={() => setShowDbPassword((v) => !v)}
                                        tabIndex={-1}
                                    >
                                        {showDbPassword ? <EyeOffIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
                                    </button>
                                </div>
                            </div>
                            <SheetFooter>
                                <Button type="button" variant="outline" onClick={() => setCreateDbOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={isCreatingDb || !dbForm.name}>
                                    {isCreatingDb ? 'Creating…' : 'Create database'}
                                </Button>
                            </SheetFooter>
                        </form>
                    </SheetContent>
                </Sheet>
            </div>
        </AppLayout>
    );
}
