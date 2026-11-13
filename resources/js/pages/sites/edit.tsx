import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';

import { FormSection, SummaryCard } from '@/components/create-form';
import InputError from '@/components/input-error';
import {
    type Repository,
    RepositorySelector,
} from '@/components/sites/repository-selector';
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
import { Switch } from '@/components/ui/switch';
import { usePermission } from '@/hooks/use-permission';
import { usePlan } from '@/hooks/use-plan';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { PhpVersion, Site } from '@/types/site';
import { SourceControlAccount } from '@/types/source-control';

interface Props {
    server: { data: Server };
    site: { data: Site };
    phpVersions: PhpVersion[];
    supportsDeployOnPush: boolean;
    sourceControlAccounts: { data: SourceControlAccount[] };
}

/** Select value for a site without a repository. */
const NO_ACCOUNT = '__none__';

type RepositoryList =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'ready'; items: Repository[] }
    | { status: 'error' };

/**
 * Fetch an account's repositories; `sync` asks the provider for a fresh
 * list. Never throws: a failure comes back as an error state.
 */
async function requestRepositories(
    url: string,
    sync: boolean,
): Promise<RepositoryList> {
    const csrfToken =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    try {
        const response = await fetch(url, {
            method: sync ? 'POST' : 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        });
        const data = await response.json();

        return response.ok
            ? { status: 'ready', items: data.repositories ?? [] }
            : { status: 'error' };
    } catch {
        return { status: 'error' };
    }
}

export default function SitesEdit({
    server,
    site,
    phpVersions,
    supportsDeployOnPush,
    sourceControlAccounts,
}: Props) {
    const teamPath = useTeamPath();
    const can = usePermission();
    const serverData = server.data;
    const siteData = site.data;
    const sitePath = teamPath(`/servers/${serverData.id}/sites/${siteData.id}`);
    const canDeploy = can('deploy');

    const accounts = sourceControlAccounts.data;
    const form = useForm({
        source_control_account_id: siteData.source_control_account_id,
        repository: siteData.repository,
        branch: siteData.branch,
        directory: siteData.directory || '/',
        php_version: siteData.php_version,
        auto_deploy: siteData.auto_deploy,
        single_page_app: siteData.single_page_app,
        octane_server: siteData.octane_server,
    });
    const octaneLock = usePlan()?.notIncluded.octane ?? null;

    const accountId = form.data.source_control_account_id;
    // The site may be connected through a teammate's account, whose
    // repositories only that teammate can list.
    const isOwnAccount = accounts.some((account) => account.id === accountId);
    // The last list fetched, and the account it belongs to; a list for
    // another account counts as still loading.
    const [fetched, setFetched] = useState<{
        accountId: number;
        list: RepositoryList;
    } | null>(null);
    const shouldLoad = accountId !== null && isOwnAccount;
    const repositories: RepositoryList = !shouldLoad
        ? { status: 'idle' }
        : fetched?.accountId === accountId
          ? fetched.list
          : { status: 'loading' };

    const repositoriesUrl = (forAccountId: number, sync: boolean) =>
        teamPath(
            sync
                ? `/source-control/${forAccountId}/repositories/sync`
                : `/source-control/${forAccountId}/repositories`,
        );

    useEffect(() => {
        if (accountId === null || !isOwnAccount) {
            return;
        }

        // Ignore a slow answer for an account that's no longer selected.
        let isCurrent = true;

        void requestRepositories(
            teamPath(`/source-control/${accountId}/repositories`),
            false,
        ).then((list) => {
            if (isCurrent) {
                setFetched({ accountId, list });
            }
        });

        return () => {
            isCurrent = false;
        };
    }, [accountId, isOwnAccount, teamPath]);

    function reloadRepositories() {
        if (accountId === null) {
            return;
        }

        const forAccountId = accountId;

        setFetched({ accountId: forAccountId, list: { status: 'loading' } });
        void requestRepositories(
            repositoriesUrl(forAccountId, true),
            true,
        ).then((list) => setFetched({ accountId: forAccountId, list }));
    }

    function handleAccountChange(value: string) {
        form.setData((current) => ({
            ...current,
            source_control_account_id:
                value === NO_ACCOUNT ? null : Number(value),
            repository: null,
        }));
    }

    function handleRepositoryChange(fullName: string, repo?: Repository) {
        form.setData((current) => ({
            ...current,
            repository: fullName || null,
            branch: repo?.default_branch || current.branch,
        }));
    }

    const hasRepository = Boolean(accountId && form.data.repository);
    const providerLabel =
        accounts.find((account) => account.id === accountId)?.provider_label ??
        siteData.repository_provider_label;
    const canUseDeployOnPush =
        hasRepository &&
        (supportsDeployOnPush ||
            form.data.repository !== siteData.repository ||
            accountId !== siteData.source_control_account_id);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: serverData.name, href: teamPath(`/servers/${serverData.id}`) },
        { title: siteData.domain, href: sitePath },
        { title: 'Edit', href: `${sitePath}/edit` },
    ];

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        // Only Deploy-permission holders may change deploy-on-push, so leave
        // it out for everyone else rather than having the request refused.
        form.transform((data) => {
            if (canDeploy) {
                return data;
            }

            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { auto_deploy, ...rest } = data;

            return rest;
        });

        form.put(sitePath);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit - ${siteData.domain}`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={sitePath}
                            aria-label={`Back to ${siteData.domain}`}
                        >
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Edit Site
                    </h1>
                    <span className="text-sm text-muted-foreground">
                        {siteData.domain}
                    </span>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_280px]"
                >
                    <Card className="gap-0 divide-y py-0">
                        <FormSection
                            title="Repository"
                            description="Where deployments pull the code from. The next deployment uses the new repository."
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="source_control_account_id">
                                        Account
                                    </Label>
                                    <Select
                                        value={
                                            accountId
                                                ? String(accountId)
                                                : NO_ACCOUNT
                                        }
                                        onValueChange={handleAccountChange}
                                    >
                                        <SelectTrigger
                                            id="source_control_account_id"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NO_ACCOUNT}>
                                                No repository
                                            </SelectItem>
                                            {accountId && !isOwnAccount && (
                                                <SelectItem
                                                    value={String(accountId)}
                                                >
                                                    A teammate&apos;s account
                                                </SelectItem>
                                            )}
                                            {accounts.map((account) => (
                                                <SelectItem
                                                    key={account.id}
                                                    value={String(account.id)}
                                                >
                                                    {account.provider_label}
                                                    <span className="text-muted-foreground">
                                                        @
                                                        {
                                                            account.provider_username
                                                        }
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={
                                            form.errors
                                                .source_control_account_id
                                        }
                                    />
                                </div>

                                {accountId && (
                                    <div className="space-y-2">
                                        <Label htmlFor="repository">
                                            Repository
                                        </Label>
                                        {isOwnAccount ? (
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
                                                onReload={reloadRepositories}
                                                value={
                                                    form.data.repository ?? ''
                                                }
                                                onChange={
                                                    handleRepositoryChange
                                                }
                                                disabled={
                                                    repositories.status ===
                                                    'loading'
                                                }
                                                placeholder="Select a repository"
                                            />
                                        ) : (
                                            <p className="py-2 font-mono text-sm">
                                                {form.data.repository}
                                            </p>
                                        )}
                                        {repositories.status === 'error' && (
                                            <p className="text-xs text-destructive">
                                                Could not load repositories from
                                                the provider.
                                            </p>
                                        )}
                                        <InputError
                                            message={form.errors.repository}
                                        />
                                    </div>
                                )}
                            </div>

                            {hasRepository && (
                                <div className="space-y-2 sm:max-w-sm">
                                    <Label htmlFor="branch">Branch</Label>
                                    <Input
                                        id="branch"
                                        value={form.data.branch}
                                        onChange={(e) =>
                                            form.setData(
                                                'branch',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.branch} />
                                </div>
                            )}
                        </FormSection>

                        <FormSection
                            title="Web directory and PHP"
                            description="The folder the web server serves, and the PHP version the site runs on. Changes reach the web server within a few moments."
                        >
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

                                {siteData.project_type !== 'html' && (
                                    <div className="space-y-2">
                                        <Label htmlFor="php_version">PHP</Label>
                                        <Select
                                            value={form.data.php_version}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'php_version',
                                                    value,
                                                )
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
                                )}
                            </div>
                        </FormSection>

                        {siteData.project_type === 'html' && (
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

                        {siteData.project_type === 'laravel' && (
                            <FormSection
                                title="Laravel Octane"
                                description={
                                    octaneLock !== null &&
                                    form.data.octane_server === null
                                        ? octaneLock.message
                                        : "Keeps the app loaded in memory with FrankenPHP so pages respond faster. The project must include laravel/octane and trust 127.0.0.1 as a proxy. Requests run on FrankenPHP's own built-in PHP, not the PHP version chosen above. Visitors may see errors for a second or two on each deploy while Octane restarts."
                                }
                                action={
                                    <Switch
                                        aria-label="Laravel Octane"
                                        checked={
                                            form.data.octane_server !== null
                                        }
                                        disabled={
                                            octaneLock !== null &&
                                            form.data.octane_server === null
                                        }
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'octane_server',
                                                checked ? 'frankenphp' : null,
                                            )
                                        }
                                    />
                                }
                            >
                                {form.errors.octane_server && (
                                    <InputError
                                        message={form.errors.octane_server}
                                    />
                                )}
                            </FormSection>
                        )}

                        {canDeploy && (
                            <FormSection
                                title="Deploy on push"
                                description={
                                    canUseDeployOnPush
                                        ? `Deploy automatically whenever someone pushes to ${form.data.branch}. A webhook is added to the repository on ${providerLabel}.`
                                        : 'Available for sites with a repository from a connected GitHub, GitLab or Bitbucket account.'
                                }
                                action={
                                    <Switch
                                        aria-label="Deploy on push"
                                        checked={form.data.auto_deploy}
                                        disabled={
                                            !canUseDeployOnPush &&
                                            !form.data.auto_deploy
                                        }
                                        onCheckedChange={(checked) =>
                                            form.setData('auto_deploy', checked)
                                        }
                                    />
                                }
                            >
                                {form.errors.auto_deploy && (
                                    <InputError
                                        message={form.errors.auto_deploy}
                                    />
                                )}
                            </FormSection>
                        )}
                    </Card>

                    <SummaryCard
                        rows={[
                            { label: 'Server', value: serverData.name },
                            {
                                label: 'Repository',
                                value: hasRepository
                                    ? form.data.repository
                                    : null,
                            },
                            {
                                label: 'Branch',
                                value: hasRepository ? form.data.branch : null,
                            },
                            {
                                label: 'Type',
                                value: siteData.framework_label,
                            },
                            {
                                label: 'PHP',
                                value:
                                    siteData.project_type !== 'html'
                                        ? form.data.php_version
                                        : null,
                            },
                        ]}
                        submitLabel="Save changes"
                        processing={form.processing}
                        disabled={
                            !form.isDirty ||
                            (accountId !== null && !form.data.repository)
                        }
                        cancelHref={sitePath}
                    />
                </form>
            </div>
        </AppLayout>
    );
}
