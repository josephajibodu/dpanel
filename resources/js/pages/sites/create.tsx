import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ArrowsClockwiseIcon,
    CheckIcon,
    CircleNotchIcon,
    EyeIcon,
    EyeSlashIcon,
    PlusIcon,
    XCircleIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    FormSection,
    SegmentedControl,
    SummaryCard,
} from '@/components/create-form';
import InputError from '@/components/input-error';
import { FrameworkIcon } from '@/components/sites/framework-icon';
import {
    type Repository,
    RepositorySelector,
} from '@/components/sites/repository-selector';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server, ServerDatabase } from '@/types/server';
import { DetectedFramework, Framework, PhpVersion } from '@/types/site';
import { SourceControlAccount } from '@/types/source-control';

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
    frameworks: Framework[];
    phpVersions: PhpVersion[];
    databases: { data: ServerDatabase[] };
    sourceControl?: SourceControlData;
}

type DomainMode = 'free' | 'custom';

type SiteNameStatus = 'idle' | 'checking' | 'available' | 'taken' | 'invalid';

/** Where looking at the chosen repository's files has got to. */
type Detection = 'idle' | 'checking' | 'detected' | 'unknown';

/** The state of fetching a list from the source control provider. */
type Remote<T> =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'ready'; items: T[] }
    | { status: 'error'; message: string };

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

/** Select value that opens the "connect a provider" dialog instead of picking an account. */
const ADD_ACCOUNT = '__add__';

/** Select value for the top of the repository, since a select can't hold an empty value. */
const REPOSITORY_ROOT = '/';

/** Select value that opens the "create database" sheet instead of picking a database. */
const CREATE_DATABASE = '__create__';

const emptyDbForm = {
    name: '',
    charset: '',
    collation: '',
    db_user: '',
    db_password: '',
};

/**
 * Derive a valid subdomain label from a repository name.
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

function generatePassword(): string {
    const chars =
        'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    const array = new Uint32Array(24);
    crypto.getRandomValues(array);

    return Array.from(array, (value) => chars[value % chars.length]).join('');
}

/**
 * Call one of FlitOps' JSON endpoints, sending the CSRF token for writes.
 * Never throws: a network failure comes back as `ok: false`.
 */
async function requestJson<T>(
    url: string,
    options: { method?: 'GET' | 'POST'; body?: unknown } = {},
): Promise<{ ok: boolean; data: Partial<T> & { message?: string } }> {
    const csrfToken =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    try {
        const response = await fetch(url, {
            method: options.method ?? 'GET',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body:
                options.body === undefined
                    ? undefined
                    : JSON.stringify(options.body),
        });

        return {
            ok: response.ok,
            data: await response.json().catch(() => ({})),
        };
    } catch {
        return {
            ok: false,
            data: {
                message:
                    'Could not reach FlitOps. Check your connection and try again.',
            } as Partial<T> & { message?: string },
        };
    }
}

export default function SitesCreate({
    server,
    freeDomain,
    frameworks,
    phpVersions,
    databases,
    sourceControl,
}: Props) {
    const teamPath = useTeamPath();
    const { data: serverData } = server;
    const sourceControlAccounts = sourceControl?.accounts.data ?? [];

    const [repositories, setRepositories] = useState<Remote<Repository>>({
        status: 'idle',
    });
    const [branches, setBranches] = useState<Remote<Branch>>({
        status: 'idle',
    });
    const [domainMode, setDomainMode] = useState<DomainMode>('free');
    const [connectDatabase, setConnectDatabase] = useState(false);
    const [createDbOpen, setCreateDbOpen] = useState(false);
    const [isCreatingDb, setIsCreatingDb] = useState(false);
    const [showDbPassword, setShowDbPassword] = useState(false);
    const [dbForm, setDbForm] = useState(emptyDbForm);
    const [providerPickerOpen, setProviderPickerOpen] = useState(false);
    const pendingDbNameRef = useRef<string | null>(null);
    const [siteNameStatus, setSiteNameStatus] =
        useState<SiteNameStatus>('idle');
    const [siteNameMessage, setSiteNameMessage] = useState<string | null>(null);
    const [detection, setDetection] = useState<Detection>('idle');
    const [folders, setFolders] = useState<string[]>([]);

    const form = useForm({
        server_id: serverData.id,
        domain: '',
        site_name: '',
        directory: '/public',
        app_directory: '',
        repository: '',
        repository_provider:
            sourceControlAccounts[0]?.provider ?? ('github' as string),
        branch: 'main',
        framework: 'laravel',
        php_version: serverData.php_version || phpVersions[0]?.value || '8.3',
        server_database_id: undefined as number | undefined,
        package_manager: 'npm',
        build_command: 'npm run build',
        auto_deploy: false,
        single_page_app: false,
        isolated: true,
        source_control_account_id: sourceControlAccounts[0]?.id as
            | number
            | undefined,
    });

    const accountId = form.data.source_control_account_id;
    const repository = form.data.repository;
    const branch = form.data.branch;
    const appDirectory = form.data.app_directory;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: serverData.name, href: teamPath(`/servers/${serverData.id}`) },
        {
            title: 'New Site',
            href: teamPath(`/servers/${serverData.id}/sites/create`),
        },
    ];

    const dbList = databases?.data ?? [];
    const readyDatabases = dbList.filter((db) => db.status === 'ready');
    const pendingDatabases = dbList.filter((db) => db.status === 'pending');

    // Pick a database created from this page as soon as it's ready.
    useEffect(() => {
        if (pendingDbNameRef.current) {
            const newDb = readyDatabases.find(
                (db) => db.name === pendingDbNameRef.current,
            );
            if (newDb) {
                form.setData('server_database_id', newDb.id);
                pendingDbNameRef.current = null;
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [readyDatabases]);

    /** Load the account's repositories; `sync` asks the provider for a fresh list. */
    const loadRepositories = useCallback(
        async (sync = false) => {
            if (!accountId) {
                setRepositories({ status: 'idle' });

                return;
            }

            setRepositories({ status: 'loading' });

            const { ok, data } = await requestJson<{
                repositories: Repository[];
            }>(
                teamPath(
                    sync
                        ? `/source-control/${accountId}/repositories/sync`
                        : `/source-control/${accountId}/repositories`,
                ),
                { method: sync ? 'POST' : 'GET' },
            );

            setRepositories(
                ok
                    ? { status: 'ready', items: data.repositories ?? [] }
                    : {
                          status: 'error',
                          message:
                              data.message ??
                              'Could not load repositories from the provider.',
                      },
            );
        },
        [accountId, teamPath],
    );

    useEffect(() => {
        void loadRepositories();
    }, [loadRepositories]);

    const loadBranches = useCallback(async () => {
        if (!repository || !accountId) {
            setBranches({ status: 'idle' });

            return;
        }

        setBranches({ status: 'loading' });

        const { ok, data } = await requestJson<{ branches: Branch[] }>(
            teamPath(
                `/source-control/${accountId}/repositories/${encodeURIComponent(repository)}/branches`,
            ),
        );

        if (!ok) {
            setBranches({
                status: 'error',
                message: data.message ?? 'Could not load branches.',
            });

            return;
        }

        const items = data.branches ?? [];
        setBranches({ status: 'ready', items });

        // Keep the chosen branch if it exists, otherwise fall back to main/master.
        form.setData((current) => {
            if (
                items.length === 0 ||
                items.some((branch) => branch.name === current.branch)
            ) {
                return current;
            }

            const fallback =
                items.find((branch) => branch.name === 'main') ??
                items.find((branch) => branch.name === 'master') ??
                items[0];

            return { ...current, branch: fallback.name };
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [repository, accountId, teamPath]);

    useEffect(() => {
        void loadBranches();
    }, [loadBranches]);

    // Look at the chosen repository's files (in the app folder, when one is
    // picked) and fill in what the app is built with.
    useEffect(() => {
        if (!repository || !accountId || !branch) {
            setDetection('idle');
            setFolders([]);

            return;
        }

        let cancelled = false;
        setDetection('checking');

        const query = new URLSearchParams({ branch });

        if (appDirectory) {
            query.set('folder', appDirectory);
        }

        void requestJson<{
            detected: DetectedFramework | null;
            folders: string[];
        }>(
            teamPath(
                `/source-control/${accountId}/repositories/${encodeURIComponent(repository)}/framework?${query}`,
            ),
        ).then(({ ok, data }) => {
            if (cancelled) {
                return;
            }

            const detected = ok ? data.detected : null;

            setFolders((ok && data.folders) || []);

            if (!detected) {
                setDetection('unknown');

                return;
            }

            setDetection('detected');
            form.setData((current) => ({
                ...current,
                framework: detected.framework,
                directory: detected.directory,
                single_page_app: detected.single_page_app,
                // Frameworks that don't build with Node say nothing about
                // the build, so what's already chosen stays.
                package_manager:
                    detected.package_manager ?? current.package_manager,
                build_command: detected.package_manager
                    ? (detected.build_command ?? '')
                    : current.build_command,
            }));
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [repository, accountId, branch, appDirectory, teamPath]);

    // Check the free subdomain is still available, a moment after typing stops.
    useEffect(() => {
        if (domainMode !== 'free') {
            return;
        }

        const siteName = form.data.site_name.trim();

        if (!siteName) {
            setSiteNameStatus('idle');
            setSiteNameMessage(null);

            return;
        }

        if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(siteName)) {
            setSiteNameStatus('invalid');
            setSiteNameMessage(
                'Only lowercase letters, numbers, and hyphens are allowed.',
            );

            return;
        }

        setSiteNameStatus('checking');
        setSiteNameMessage(null);

        const timeout = setTimeout(async () => {
            const { ok, data } = await requestJson<{
                available: boolean;
                errors: { site_name?: string[] };
            }>(teamPath(`/servers/${serverData.id}/sites/check-site-name`), {
                method: 'POST',
                body: { site_name: siteName },
            });

            if (!ok) {
                setSiteNameStatus('invalid');
                setSiteNameMessage(
                    data.errors?.site_name?.[0] ??
                        data.message ??
                        'Invalid site name.',
                );

                return;
            }

            setSiteNameStatus(data.available ? 'available' : 'taken');
            setSiteNameMessage(data.available ? null : (data.message ?? null));
        }, 400);

        return () => clearTimeout(timeout);
    }, [form.data.site_name, domainMode, serverData.id, teamPath]);

    // Pick up an account connected in the OAuth popup.
    useEffect(() => {
        const previousIds = new Set(
            sourceControlAccounts.map((account) => account.id),
        );

        function handleMessage(event: MessageEvent) {
            if (event.origin !== window.location.origin) {
                return;
            }

            const data = event.data as
                | { type?: string; status?: string; error?: string }
                | undefined;

            if (data?.type !== 'flitops:source-control') {
                return;
            }

            if (data.status === 'connected') {
                router.reload({
                    only: ['sourceControl'],
                    onSuccess: (page) => {
                        const newAccounts =
                            (
                                page.props.sourceControl as
                                    | SourceControlData
                                    | undefined
                            )?.accounts.data ?? [];
                        const added = newAccounts.find(
                            (account) => !previousIds.has(account.id),
                        );
                        if (added) {
                            selectAccount(added);
                        }
                    },
                });
            } else if (data.status === 'failed') {
                toast.error(
                    data.error ?? 'Failed to connect source control provider.',
                );
            }
        }

        window.addEventListener('message', handleMessage);

        return () => window.removeEventListener('message', handleMessage);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sourceControlAccounts]);

    function selectAccount(account: SourceControlAccount) {
        form.setData((current) => ({
            ...current,
            source_control_account_id: account.id,
            repository_provider: account.provider,
            repository: '',
            app_directory: '',
            branch: 'main',
            site_name: '',
        }));
    }

    function handleAccountChange(value: string) {
        if (value === ADD_ACCOUNT) {
            setProviderPickerOpen(true);

            return;
        }

        const account = sourceControlAccounts.find(
            (option) => option.id === Number(value),
        );
        if (account && account.id !== accountId) {
            selectAccount(account);
        }
    }

    function handleRepositoryChange(fullName: string, repo?: Repository) {
        const projectName = repo?.name ?? fullName.split('/')[1] ?? '';

        form.setData((current) => ({
            ...current,
            repository: fullName,
            app_directory: '',
            branch: repo?.default_branch || current.branch,
            site_name: slugifyProjectName(projectName),
        }));
    }

    function handleFrameworkChange(value: string) {
        const framework = frameworks.find((option) => option.value === value);

        if (!framework) {
            return;
        }

        setDetection('idle');
        form.setData((current) => {
            // A framework that builds needs a package manager; plain static
            // files have nothing to build. PHP projects keep what's chosen.
            let packageManager = current.package_manager;

            if (framework.buildsWithNode && packageManager === 'none') {
                packageManager = 'npm';
            } else if (
                !framework.buildsWithNode &&
                framework.projectType === 'html'
            ) {
                packageManager = 'none';
            }

            return {
                ...current,
                framework: framework.value,
                directory: framework.defaultDirectory,
                single_page_app: framework.singlePageApp,
                package_manager: packageManager,
                build_command:
                    packageManager === current.package_manager
                        ? current.build_command
                        : (DEFAULT_BUILD_COMMANDS[packageManager] ?? ''),
            };
        });
    }

    function handlePackageManagerChange(value: string) {
        form.setData((current) => ({
            ...current,
            package_manager: value,
            build_command: DEFAULT_BUILD_COMMANDS[value] ?? '',
        }));
    }

    function handleDatabaseToggle(checked: boolean) {
        setConnectDatabase(checked);
        if (!checked) {
            form.setData('server_database_id', undefined);
        }
    }

    function openProviderPopup(provider: OAuthProvider) {
        const returnUrl = window.location.pathname + window.location.search;
        const url = `/auth/${provider}/redirect?popup=1&redirect=${encodeURIComponent(returnUrl)}`;
        const width = 600;
        const height = 700;
        const left = Math.max(
            0,
            window.screenX + (window.outerWidth - width) / 2,
        );
        const top = Math.max(
            0,
            window.screenY + (window.outerHeight - height) / 2,
        );
        const popup = window.open(
            url,
            'source-control-oauth',
            `width=${width},height=${height},left=${left},top=${top}`,
        );

        if (!popup) {
            toast.error(
                'Please allow popups to connect a source control provider.',
            );
        }
    }

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
                    setDbForm(emptyDbForm);
                    setShowDbPassword(false);
                },
                onFinish: () => setIsCreatingDb(false),
            },
        );
    }

    const siteNameBlocked =
        siteNameStatus === 'taken' ||
        siteNameStatus === 'invalid' ||
        siteNameStatus === 'checking';

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (domainMode === 'free' && siteNameBlocked) {
            toast.error(
                siteNameMessage ??
                    'Choose an available free subdomain before continuing.',
            );

            return;
        }

        // Send only the field for the chosen domain mode, so the backend's
        // required_without rule resolves cleanly (site_name is filled from the
        // repository even when a custom domain is used).
        form.transform((data) => ({
            ...data,
            domain: domainMode === 'custom' ? data.domain : '',
            site_name: domainMode === 'free' ? data.site_name : '',
        }));

        form.post(teamPath(`/servers/${serverData.id}/sites`));
    }

    const selectedFramework = frameworks.find(
        (framework) => framework.value === form.data.framework,
    );
    const selectedDatabase = readyDatabases.find(
        (db) => db.id === form.data.server_database_id,
    );
    const hostname =
        domainMode === 'custom'
            ? form.data.domain || null
            : form.data.site_name
              ? `${form.data.site_name}.${freeDomain}`
              : null;

    const isSubmitBlocked =
        (domainMode === 'custom'
            ? !form.data.domain
            : !form.data.site_name || siteNameBlocked) ||
        (connectDatabase && !form.data.server_database_id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`New Site - ${serverData.name}`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={teamPath(`/servers/${serverData.id}`)}
                            aria-label={`Back to ${serverData.name}`}
                        >
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <h1 className="text-xl font-semibold tracking-tight">
                        New Site
                    </h1>
                    <span className="text-sm text-muted-foreground">
                        on {serverData.name}
                    </span>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_280px]"
                >
                    <Card className="gap-0 divide-y py-0">
                        <FormSection
                            title="Repository"
                            description="Where deployments pull the code from. You can skip this and add one later."
                        >
                            {sourceControlAccounts.length === 0 ? (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-dashed px-4 py-3">
                                    <p className="text-sm text-muted-foreground">
                                        No GitHub, GitLab or Bitbucket account
                                        connected yet.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setProviderPickerOpen(true)
                                        }
                                    >
                                        Connect a provider
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="source_control_account_id">
                                                Account
                                            </Label>
                                            <Select
                                                value={
                                                    accountId
                                                        ? String(accountId)
                                                        : ''
                                                }
                                                onValueChange={
                                                    handleAccountChange
                                                }
                                            >
                                                <SelectTrigger
                                                    id="source_control_account_id"
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Select an account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {sourceControlAccounts.map(
                                                        (account) => (
                                                            <SelectItem
                                                                key={account.id}
                                                                value={String(
                                                                    account.id,
                                                                )}
                                                            >
                                                                {
                                                                    account.provider_label
                                                                }
                                                                <span className="text-muted-foreground">
                                                                    @
                                                                    {
                                                                        account.provider_username
                                                                    }
                                                                </span>
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                    <SelectSeparator />
                                                    <SelectItem
                                                        value={ADD_ACCOUNT}
                                                        className="text-primary"
                                                    >
                                                        <PlusIcon className="h-4 w-4" />
                                                        Connect another account
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    form.errors
                                                        .source_control_account_id
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="repository">
                                                Repository
                                            </Label>
                                            {repositories.status === 'error' ? (
                                                <RemoteError
                                                    message={
                                                        repositories.message
                                                    }
                                                    onRetry={() =>
                                                        void loadRepositories()
                                                    }
                                                />
                                            ) : (
                                                <RepositorySelector
                                                    repositories={
                                                        repositories.status ===
                                                        'ready'
                                                            ? repositories.items
                                                            : []
                                                    }
                                                    loadingRepositories={
                                                        repositories.status ===
                                                        'loading'
                                                    }
                                                    onReload={() =>
                                                        void loadRepositories(
                                                            true,
                                                        )
                                                    }
                                                    value={repository}
                                                    onChange={
                                                        handleRepositoryChange
                                                    }
                                                    disabled={
                                                        repositories.status ===
                                                        'loading'
                                                    }
                                                    placeholder="Select a repository"
                                                />
                                            )}
                                            {repositories.status === 'ready' &&
                                                repositories.items.length ===
                                                    0 && (
                                                    <p className="text-xs text-muted-foreground">
                                                        No repositories found
                                                        for this account.
                                                    </p>
                                                )}
                                            <InputError
                                                message={form.errors.repository}
                                            />
                                        </div>
                                    </div>

                                    {repository && (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <BranchField
                                                branches={branches}
                                                value={form.data.branch}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'branch',
                                                        value,
                                                    )
                                                }
                                                onRetry={() =>
                                                    void loadBranches()
                                                }
                                                error={form.errors.branch}
                                            />
                                            <AppFolderField
                                                folders={folders}
                                                value={appDirectory}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'app_directory',
                                                        value,
                                                    )
                                                }
                                                disabled={
                                                    detection === 'checking'
                                                }
                                                error={
                                                    form.errors.app_directory
                                                }
                                            />
                                        </div>
                                    )}
                                </>
                            )}
                        </FormSection>

                        <FormSection
                            title="Domain"
                            description={
                                domainMode === 'free'
                                    ? `Every site gets a free ${freeDomain} subdomain.`
                                    : 'Point the domain at this server once the site is created.'
                            }
                            action={
                                <SegmentedControl
                                    label="Domain type"
                                    value={domainMode}
                                    onChange={setDomainMode}
                                    options={[
                                        { value: 'free', label: 'Free' },
                                        { value: 'custom', label: 'Custom' },
                                    ]}
                                />
                            }
                        >
                            {domainMode === 'custom' ? (
                                <div className="space-y-2">
                                    <Input
                                        id="domain"
                                        aria-label="Domain"
                                        placeholder="example.com"
                                        value={form.data.domain}
                                        onChange={(e) =>
                                            form.setData(
                                                'domain',
                                                e.target.value,
                                            )
                                        }
                                        className="sm:max-w-sm"
                                    />
                                    <InputError message={form.errors.domain} />
                                </div>
                            ) : (
                                <SubdomainField
                                    value={form.data.site_name}
                                    onChange={(value) =>
                                        form.setData('site_name', value)
                                    }
                                    freeDomain={freeDomain}
                                    status={siteNameStatus}
                                    message={
                                        form.errors.site_name ?? siteNameMessage
                                    }
                                    hasRepository={repository !== ''}
                                />
                            )}
                        </FormSection>

                        <FormSection
                            title="Project type"
                            description="Worked out from the repository when you pick one. Sets the web directory and the starting deploy script."
                        >
                            <div className="space-y-2">
                                <Label htmlFor="framework">Framework</Label>
                                <Select
                                    value={form.data.framework}
                                    onValueChange={handleFrameworkChange}
                                >
                                    <SelectTrigger
                                        id="framework"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a framework" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {frameworks.map((framework) => (
                                            <SelectItem
                                                key={framework.value}
                                                value={framework.value}
                                            >
                                                <FrameworkIcon
                                                    framework={framework.value}
                                                />
                                                {framework.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <FrameworkNote
                                    detection={detection}
                                    description={selectedFramework?.description}
                                    canPickFolder={
                                        folders.length > 0 && !appDirectory
                                    }
                                />
                                <InputError message={form.errors.framework} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="directory">
                                        Web directory
                                    </Label>
                                    <Input
                                        id="directory"
                                        placeholder="/public"
                                        value={form.data.directory}
                                        onChange={(e) =>
                                            form.setData(
                                                'directory',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.directory}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="php_version">PHP</Label>
                                    <Select
                                        value={form.data.php_version}
                                        onValueChange={(value) =>
                                            form.setData('php_version', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="php_version"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Select PHP version" />
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
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.php_version}
                                    />
                                </div>
                            </div>
                        </FormSection>

                        {selectedFramework?.projectType === 'html' && (
                            <FormSection
                                title="Single-page app"
                                description="For React, Vue, Angular and similar apps. Any URL that isn't a file loads index.html, so opening or refreshing a page inside the app works. Leave it off for sites with a file for each page."
                                action={
                                    <Switch
                                        aria-label="Single-page app"
                                        checked={form.data.single_page_app}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'single_page_app',
                                                checked,
                                            )
                                        }
                                    />
                                }
                            >
                                {form.errors.single_page_app && (
                                    <InputError
                                        message={form.errors.single_page_app}
                                    />
                                )}
                            </FormSection>
                        )}

                        <FormSection
                            title="Frontend build"
                            description="Runs on every deployment to build your assets."
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="package_manager">
                                        Package manager
                                    </Label>
                                    <Select
                                        value={form.data.package_manager}
                                        onValueChange={
                                            handlePackageManagerChange
                                        }
                                    >
                                        <SelectTrigger
                                            id="package_manager"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {PACKAGE_MANAGERS.map((pm) => (
                                                <SelectItem
                                                    key={pm.value}
                                                    value={pm.value}
                                                >
                                                    {pm.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.package_manager}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="build_command">
                                        Build command
                                    </Label>
                                    <Input
                                        id="build_command"
                                        placeholder="npm run build"
                                        value={form.data.build_command}
                                        onChange={(e) =>
                                            form.setData(
                                                'build_command',
                                                e.target.value,
                                            )
                                        }
                                        disabled={
                                            form.data.package_manager === 'none'
                                        }
                                    />
                                    <InputError
                                        message={form.errors.build_command}
                                    />
                                </div>
                            </div>
                        </FormSection>

                        <FormSection
                            title="Database"
                            description="Connect a database and its credentials are added to the site's environment."
                            action={
                                <Switch
                                    aria-label="Connect a database"
                                    checked={connectDatabase}
                                    onCheckedChange={handleDatabaseToggle}
                                />
                            }
                        >
                            {connectDatabase && (
                                <DatabaseField
                                    server={serverData}
                                    readyDatabases={readyDatabases}
                                    pendingDatabases={pendingDatabases}
                                    value={form.data.server_database_id}
                                    onChange={(id) =>
                                        form.setData('server_database_id', id)
                                    }
                                    onCreate={() => setCreateDbOpen(true)}
                                    error={form.errors.server_database_id}
                                />
                            )}
                        </FormSection>

                        <FormSection
                            title="Isolate this site"
                            description="Recommended. Runs the site as its own system user, so if it's ever hacked it can't reach your other sites or take over the server."
                            action={
                                <Switch
                                    aria-label="Isolate this site"
                                    checked={form.data.isolated}
                                    onCheckedChange={(checked) =>
                                        form.setData('isolated', checked)
                                    }
                                />
                            }
                        >
                            {form.errors.isolated && (
                                <InputError message={form.errors.isolated} />
                            )}
                        </FormSection>
                    </Card>

                    <SummaryCard
                        rows={[
                            { label: 'Server', value: serverData.name },
                            { label: 'Domain', value: hostname },
                            {
                                label: 'Repository',
                                value: repository || null,
                            },
                            {
                                label: 'Branch',
                                value: repository ? form.data.branch : null,
                            },
                            {
                                label: 'Type',
                                value: selectedFramework?.label ?? null,
                            },
                            {
                                label: 'PHP',
                                value: form.data.php_version || null,
                            },
                            {
                                label: 'Database',
                                value: selectedDatabase?.name ?? null,
                            },
                        ]}
                        submitLabel="Create site"
                        processing={form.processing}
                        disabled={isSubmitBlocked}
                        cancelHref={teamPath(`/servers/${serverData.id}`)}
                    />
                </form>

                <Dialog
                    open={providerPickerOpen}
                    onOpenChange={setProviderPickerOpen}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                Connect a source control provider
                            </DialogTitle>
                            <DialogDescription>
                                A new window opens to authorize the provider.
                                What you've filled in here stays on this page.
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

                <Sheet open={createDbOpen} onOpenChange={setCreateDbOpen}>
                    <SheetContent side="right">
                        <SheetHeader>
                            <SheetTitle>Create database</SheetTitle>
                        </SheetHeader>
                        <form
                            onSubmit={handleCreateDatabase}
                            className="flex flex-1 flex-col gap-4 p-4 pt-4"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="new-db-name">Name</Label>
                                <Input
                                    id="new-db-name"
                                    value={dbForm.name}
                                    onChange={(e) =>
                                        setDbForm((p) => ({
                                            ...p,
                                            name: e.target.value,
                                        }))
                                    }
                                    placeholder="mydb"
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="new-db-charset">
                                    Charset (optional)
                                </Label>
                                <Input
                                    id="new-db-charset"
                                    value={dbForm.charset}
                                    onChange={(e) =>
                                        setDbForm((p) => ({
                                            ...p,
                                            charset: e.target.value,
                                        }))
                                    }
                                    placeholder="utf8mb4"
                                    className="font-mono"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="new-db-collation">
                                    Collation (optional)
                                </Label>
                                <Input
                                    id="new-db-collation"
                                    value={dbForm.collation}
                                    onChange={(e) =>
                                        setDbForm((p) => ({
                                            ...p,
                                            collation: e.target.value,
                                        }))
                                    }
                                    placeholder="utf8mb4_unicode_ci"
                                    className="font-mono"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="new-db-user">
                                    Database user (optional)
                                </Label>
                                <Input
                                    id="new-db-user"
                                    value={dbForm.db_user}
                                    onChange={(e) =>
                                        setDbForm((p) => ({
                                            ...p,
                                            db_user: e.target.value,
                                        }))
                                    }
                                    placeholder="Leave blank for default user"
                                    className="font-mono"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="new-db-password">
                                        Password
                                    </Label>
                                    <button
                                        type="button"
                                        className="text-sm font-medium text-primary hover:underline"
                                        onClick={() => {
                                            setDbForm((p) => ({
                                                ...p,
                                                db_password: generatePassword(),
                                            }));
                                            setShowDbPassword(true);
                                        }}
                                    >
                                        Generate password
                                    </button>
                                </div>
                                <div className="relative">
                                    <Input
                                        id="new-db-password"
                                        type={
                                            showDbPassword ? 'text' : 'password'
                                        }
                                        value={dbForm.db_password}
                                        onChange={(e) =>
                                            setDbForm((p) => ({
                                                ...p,
                                                db_password: e.target.value,
                                            }))
                                        }
                                        placeholder="••••••••"
                                        autoComplete="new-password"
                                        className="pr-10"
                                    />
                                    <button
                                        type="button"
                                        className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        onClick={() =>
                                            setShowDbPassword((v) => !v)
                                        }
                                        tabIndex={-1}
                                        aria-label={
                                            showDbPassword
                                                ? 'Hide password'
                                                : 'Show password'
                                        }
                                    >
                                        {showDbPassword ? (
                                            <EyeSlashIcon className="h-4 w-4" />
                                        ) : (
                                            <EyeIcon className="h-4 w-4" />
                                        )}
                                    </button>
                                </div>
                            </div>
                            <SheetFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setCreateDbOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={isCreatingDb || !dbForm.name}
                                >
                                    {isCreatingDb
                                        ? 'Creating…'
                                        : 'Create database'}
                                </Button>
                            </SheetFooter>
                        </form>
                    </SheetContent>
                </Sheet>
            </div>
        </AppLayout>
    );
}

/** A failed fetch from the provider, with a way to try again. */
function RemoteError({
    message,
    onRetry,
}: {
    message: string;
    onRetry: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2">
            <p className="text-sm text-destructive">{message}</p>
            <Button type="button" variant="outline" size="sm" onClick={onRetry}>
                <ArrowsClockwiseIcon className="h-4 w-4" />
                Try again
            </Button>
        </div>
    );
}

interface BranchFieldProps {
    branches: Remote<Branch>;
    value: string;
    onChange: (value: string) => void;
    onRetry: () => void;
    error?: string;
}

/** What the chosen framework does, and whether it was read from the repository. */
function FrameworkNote({
    detection,
    description,
    canPickFolder,
}: {
    detection: Detection;
    description?: string;
    /** Whether the app might be in a folder the user hasn't picked yet. */
    canPickFolder: boolean;
}) {
    if (detection === 'checking') {
        return (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <CircleNotchIcon className="h-3 w-3 animate-spin" />
                Checking what the repository is built with…
            </p>
        );
    }

    return (
        <p className="text-xs text-muted-foreground">
            {detection === 'detected' && 'Detected from the repository. '}
            {detection === 'unknown' &&
                (canPickFolder
                    ? "Couldn't tell what the repository is built with. If the app is in a folder, choose it under App folder; otherwise pick the framework here. "
                    : "Couldn't tell what this is built with, so pick the framework here. ")}
            {description}
        </p>
    );
}

interface AppFolderFieldProps {
    /** The folders at the top of the chosen repository. */
    folders: string[];
    /** The chosen folder; empty for the top of the repository. */
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    error?: string;
}

/** Picks the repository folder that holds the app, for a repository with more than one. */
function AppFolderField({
    folders,
    value,
    onChange,
    disabled,
    error,
}: AppFolderFieldProps) {
    return (
        <div className="space-y-2">
            <Label htmlFor="app_directory">App folder</Label>
            <Select
                value={value || REPOSITORY_ROOT}
                onValueChange={(folder) =>
                    onChange(folder === REPOSITORY_ROOT ? '' : folder)
                }
                disabled={disabled}
            >
                <SelectTrigger
                    id="app_directory"
                    className="w-full sm:max-w-sm"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={REPOSITORY_ROOT}>
                        Top of the repository
                    </SelectItem>
                    {folders.map((folder) => (
                        <SelectItem key={folder} value={folder}>
                            {folder}
                        </SelectItem>
                    ))}
                    {value && !folders.includes(value) && (
                        <SelectItem value={value}>{value}</SelectItem>
                    )}
                </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
                Where the app to deploy sits in the repository.
            </p>
            <InputError message={error} />
        </div>
    );
}

/** The branch picker, with loading and error states while branches are fetched. */
function BranchField({
    branches,
    value,
    onChange,
    onRetry,
    error,
}: BranchFieldProps) {
    const items = branches.status === 'ready' ? branches.items : [];

    return (
        <div className="space-y-2">
            <Label htmlFor="branch">Branch</Label>
            {branches.status === 'loading' ? (
                <Skeleton className="h-9 w-full sm:max-w-sm" />
            ) : branches.status === 'error' ? (
                <RemoteError message={branches.message} onRetry={onRetry} />
            ) : (
                <Select value={value} onValueChange={onChange}>
                    <SelectTrigger id="branch" className="w-full sm:max-w-sm">
                        <SelectValue placeholder="Select a branch" />
                    </SelectTrigger>
                    <SelectContent>
                        {items.length > 0 ? (
                            items.map((branch) => (
                                <SelectItem
                                    key={branch.name}
                                    value={branch.name}
                                >
                                    {branch.name}
                                    {branch.protected && (
                                        <span className="text-xs text-muted-foreground">
                                            protected
                                        </span>
                                    )}
                                </SelectItem>
                            ))
                        ) : (
                            <SelectItem value={value}>{value}</SelectItem>
                        )}
                    </SelectContent>
                </Select>
            )}
            <InputError message={error} />
        </div>
    );
}

interface SubdomainFieldProps {
    value: string;
    onChange: (value: string) => void;
    freeDomain: string;
    status: SiteNameStatus;
    message: string | null;
    hasRepository: boolean;
}

/** The free subdomain input, showing whether the name is still available. */
function SubdomainField({
    value,
    onChange,
    freeDomain,
    status,
    message,
    hasRepository,
}: SubdomainFieldProps) {
    const isProblem = status === 'taken' || status === 'invalid';

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <div className="relative flex-1 sm:max-w-sm">
                    <Input
                        id="site_name"
                        aria-label="Subdomain"
                        placeholder="my-app"
                        value={value}
                        onChange={(e) => onChange(e.target.value.toLowerCase())}
                        aria-invalid={isProblem || undefined}
                        className="pr-8"
                    />
                    <span className="absolute top-1/2 right-2 -translate-y-1/2">
                        {status === 'checking' && (
                            <CircleNotchIcon className="size-4 animate-spin text-muted-foreground" />
                        )}
                        {status === 'available' && (
                            <CheckIcon className="size-4 text-emerald-600" />
                        )}
                        {isProblem && (
                            <XCircleIcon className="size-4 text-destructive" />
                        )}
                    </span>
                </div>
                <span className="text-sm whitespace-nowrap text-muted-foreground">
                    .{freeDomain}
                </span>
            </div>
            {message ? (
                <InputError message={message} />
            ) : status === 'available' ? (
                <p className="text-sm text-emerald-600">
                    That subdomain is available.
                </p>
            ) : (
                hasRepository && (
                    <p className="text-xs text-muted-foreground">
                        Taken from the repository name. Change it if you like.
                    </p>
                )
            )}
        </div>
    );
}

interface DatabaseFieldProps {
    server: Server;
    readyDatabases: ServerDatabase[];
    pendingDatabases: ServerDatabase[];
    value: number | undefined;
    onChange: (id: number) => void;
    onCreate: () => void;
    error?: string;
}

/** The database picker, offering to create one when the server can host it. */
function DatabaseField({
    server,
    readyDatabases,
    pendingDatabases,
    value,
    onChange,
    onCreate,
    error,
}: DatabaseFieldProps) {
    const canCreate = server.database_type !== null;

    if (readyDatabases.length === 0 && pendingDatabases.length === 0) {
        return (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-dashed px-4 py-3">
                <p className="text-sm text-muted-foreground">
                    {canCreate
                        ? 'No databases on this server yet.'
                        : "This server has no database. Connect it to a database server from that server's Connections page."}
                </p>
                {canCreate && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onCreate}
                    >
                        Create database
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <Select
                value={value ? String(value) : ''}
                onValueChange={(selected) =>
                    selected === CREATE_DATABASE
                        ? onCreate()
                        : onChange(Number(selected))
                }
            >
                <SelectTrigger
                    id="server_database_id"
                    aria-label="Database"
                    className="w-full sm:max-w-sm"
                >
                    <SelectValue placeholder="Select a database" />
                </SelectTrigger>
                <SelectContent>
                    {readyDatabases.map((db) => (
                        <SelectItem key={db.id} value={String(db.id)}>
                            {db.name}
                            {db.server_id !== server.id && db.server_name && (
                                <span className="text-xs text-muted-foreground">
                                    on {db.server_name}
                                </span>
                            )}
                        </SelectItem>
                    ))}
                    {pendingDatabases.map((db) => (
                        <SelectItem key={db.id} value={String(db.id)} disabled>
                            <CircleNotchIcon className="h-3 w-3 animate-spin" />
                            {db.name}
                            <span className="text-xs text-muted-foreground">
                                creating…
                            </span>
                        </SelectItem>
                    ))}
                    {canCreate && (
                        <>
                            <SelectSeparator />
                            <SelectItem
                                value={CREATE_DATABASE}
                                className="text-primary"
                            >
                                <PlusIcon className="h-4 w-4" />
                                Create database
                            </SelectItem>
                        </>
                    )}
                </SelectContent>
            </Select>
            {pendingDatabases.length > 0 && (
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <CircleNotchIcon className="h-3 w-3 animate-spin" />
                    {pendingDatabases.length === 1
                        ? `"${pendingDatabases[0].name}" is being created…`
                        : `${pendingDatabases.length} databases are being created…`}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
