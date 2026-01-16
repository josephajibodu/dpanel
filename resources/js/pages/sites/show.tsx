import { CopyButton } from '@/components/copy-button';
import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { EnvironmentVariable, Site } from '@/types/site';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ArrowLeftIcon,
    ClockIcon,
    CodeIcon,
    ExternalLinkIcon,
    FileCodeIcon,
    GitBranchIcon,
    GlobeIcon,
    Loader2Icon,
    PlusIcon,
    RocketIcon,
    ServerIcon,
    SettingsIcon,
    Trash2Icon,
    XIcon,
} from 'lucide-react';
import { useState } from 'react';

interface Props {
    site: {
        data: Site & {
            server?: {
                id: number;
                name: string;
                ip_address: string;
            };
        };
    };
}

type TabType = 'overview' | 'deployments' | 'environment' | 'deploy-script';

export default function SitesShow({ site }: Props) {
    const { data: siteData } = site;
    const [activeTab, setActiveTab] = useState<TabType>('overview');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: siteData.server?.name || 'Server', href: `/servers/${siteData.server?.id}` },
        { title: siteData.domain, href: `/sites/${siteData.id}` },
    ];

    const tabs: { id: TabType; label: string; icon: React.ElementType }[] = [
        { id: 'overview', label: 'Overview', icon: GlobeIcon },
        { id: 'deployments', label: 'Deployments', icon: RocketIcon },
        { id: 'environment', label: 'Environment', icon: SettingsIcon },
        { id: 'deploy-script', label: 'Deploy Script', icon: CodeIcon },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={siteData.domain} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={`/servers/${siteData.server?.id}`}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">{siteData.domain}</h1>
                            <SiteStatusBadge status={siteData.status} statusLabel={siteData.status_label} statusColor={siteData.status_color} />
                        </div>
                        <p className="text-muted-foreground text-sm">{siteData.project_type_label}</p>
                    </div>
                    <Button variant="outline" asChild>
                        <a href={`https://${siteData.domain}`} target="_blank" rel="noopener noreferrer">
                            <ExternalLinkIcon className="mr-2 h-4 w-4" />
                            Visit Site
                        </a>
                    </Button>
                </div>

                {/* Tabs */}
                <div className="flex gap-1 border-b">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                                activeTab === tab.id
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            <tab.icon className="h-4 w-4" />
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* Tab Content */}
                {activeTab === 'overview' && <OverviewTab site={siteData} />}
                {activeTab === 'deployments' && <DeploymentsTab site={siteData} />}
                {activeTab === 'environment' && <EnvironmentTab site={siteData} />}
                {activeTab === 'deploy-script' && <DeployScriptTab site={siteData} />}
            </div>
        </AppLayout>
    );
}

function OverviewTab({ site }: { site: Props['site']['data'] }) {
    return (
        <div className="grid gap-6 lg:grid-cols-2">
            {/* Site Details */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <GlobeIcon className="h-5 w-5" />
                        Site Details
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Domain</span>
                        <div className="flex items-center gap-2">
                            <span className="font-medium">{site.domain}</span>
                            <CopyButton value={site.domain} className="h-7 w-7" />
                        </div>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Project Type</span>
                        <span className="font-medium">{site.project_type_label}</span>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">PHP Version</span>
                        <span className="font-medium">PHP {site.php_version}</span>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Web Directory</span>
                        <code className="bg-muted rounded px-2 py-0.5 text-sm">{site.directory}</code>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Root Path</span>
                        <code className="bg-muted max-w-[200px] truncate rounded px-2 py-0.5 text-sm">{site.root_path}</code>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Created</span>
                        <span>{format(new Date(site.created_at), 'MMM d, yyyy HH:mm')}</span>
                    </div>
                </CardContent>
            </Card>

            {/* Repository Info */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <GitBranchIcon className="h-5 w-5" />
                        Repository
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {site.repository ? (
                        <>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Provider</span>
                                <span className="font-medium">{site.repository_provider_label}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Repository</span>
                                <a
                                    href={site.repository_url || '#'}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-1 text-sm font-medium hover:underline"
                                >
                                    {site.short_repository}
                                    <ExternalLinkIcon className="h-3 w-3" />
                                </a>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Branch</span>
                                <code className="bg-muted rounded px-2 py-0.5 text-sm">{site.branch}</code>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Auto Deploy</span>
                                <span className={site.auto_deploy ? 'text-green-600' : 'text-muted-foreground'}>
                                    {site.auto_deploy ? 'Enabled' : 'Disabled'}
                                </span>
                            </div>
                        </>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-8 text-center">
                            <GitBranchIcon className="text-muted-foreground mb-2 h-8 w-8" />
                            <p className="text-muted-foreground text-sm">No repository connected.</p>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Server Info */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <ServerIcon className="h-5 w-5" />
                        Server
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Name</span>
                        <Link href={`/servers/${site.server?.id}`} className="font-medium hover:underline">
                            {site.server?.name}
                        </Link>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">IP Address</span>
                        <div className="flex items-center gap-2">
                            <code className="bg-muted rounded px-2 py-0.5 text-sm">{site.server?.ip_address}</code>
                            {site.server?.ip_address && <CopyButton value={site.server.ip_address} className="h-7 w-7" />}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Latest Deployment */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <RocketIcon className="h-5 w-5" />
                        Latest Deployment
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {site.latest_deployment ? (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Status</span>
                                <span
                                    className={`font-medium ${
                                        site.latest_deployment.status === 'finished'
                                            ? 'text-green-600'
                                            : site.latest_deployment.status === 'failed'
                                              ? 'text-red-600'
                                              : 'text-amber-600'
                                    }`}
                                >
                                    {site.latest_deployment.status_label}
                                </span>
                            </div>
                            {site.latest_deployment.commit_hash && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Commit</span>
                                    <code className="bg-muted rounded px-2 py-0.5 text-sm">
                                        {site.latest_deployment.commit_hash.slice(0, 7)}
                                    </code>
                                </div>
                            )}
                            {site.latest_deployment.finished_at && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Deployed</span>
                                    <span>{format(new Date(site.latest_deployment.finished_at), 'MMM d, yyyy HH:mm')}</span>
                                </div>
                            )}
                            {site.latest_deployment.duration_seconds && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">Duration</span>
                                    <span>{site.latest_deployment.duration_seconds}s</span>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-8 text-center">
                            <RocketIcon className="text-muted-foreground mb-2 h-8 w-8" />
                            <p className="text-muted-foreground text-sm">No deployments yet.</p>
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function DeploymentsTab({ site }: { site: Props['site']['data'] }) {
    const deployments = site.deployments || [];

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div>
                        <CardTitle>Deployment History</CardTitle>
                        <CardDescription>Recent deployments for this site.</CardDescription>
                    </div>
                    <Button disabled>
                        <RocketIcon className="mr-2 h-4 w-4" />
                        Deploy Now
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {deployments.length > 0 ? (
                    <div className="space-y-3">
                        {deployments.map((deployment) => (
                            <div key={deployment.id} className="flex items-center justify-between rounded-lg border p-4">
                                <div className="flex items-center gap-4">
                                    <div
                                        className={`flex h-10 w-10 items-center justify-center rounded-full ${
                                            deployment.status === 'finished'
                                                ? 'bg-green-100 text-green-600 dark:bg-green-900'
                                                : deployment.status === 'failed'
                                                  ? 'bg-red-100 text-red-600 dark:bg-red-900'
                                                  : 'bg-amber-100 text-amber-600 dark:bg-amber-900'
                                        }`}
                                    >
                                        <RocketIcon className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <p className="font-medium">{deployment.status_label}</p>
                                        <p className="text-muted-foreground text-sm">
                                            {deployment.commit_hash ? (
                                                <>
                                                    <code className="mr-2">{deployment.commit_hash.slice(0, 7)}</code>
                                                    {deployment.commit_message && (
                                                        <span className="max-w-[200px] truncate">{deployment.commit_message}</span>
                                                    )}
                                                </>
                                            ) : (
                                                <span>Manual deployment</span>
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <div className="text-right">
                                    <p className="text-sm">
                                        {deployment.finished_at
                                            ? format(new Date(deployment.finished_at), 'MMM d, HH:mm')
                                            : deployment.started_at
                                              ? 'In progress...'
                                              : 'Pending'}
                                    </p>
                                    {deployment.duration_seconds && (
                                        <p className="text-muted-foreground flex items-center justify-end gap-1 text-sm">
                                            <ClockIcon className="h-3 w-3" />
                                            {deployment.duration_seconds}s
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center py-12 text-center">
                        <RocketIcon className="text-muted-foreground mb-3 h-12 w-12" />
                        <h3 className="mb-1 font-medium">No deployments yet</h3>
                        <p className="text-muted-foreground text-sm">Deploy your site to see deployment history here.</p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function EnvironmentTab({ site }: { site: Props['site']['data'] }) {
    const [variables, setVariables] = useState<EnvironmentVariable[]>([{ key: '', value: '' }]);

    const form = useForm({
        variables: variables,
    });

    function addVariable() {
        setVariables([...variables, { key: '', value: '' }]);
    }

    function removeVariable(index: number) {
        const newVariables = variables.filter((_, i) => i !== index);
        setVariables(newVariables.length > 0 ? newVariables : [{ key: '', value: '' }]);
    }

    function updateVariable(index: number, field: 'key' | 'value', value: string) {
        const newVariables = [...variables];
        newVariables[index][field] = value;
        setVariables(newVariables);
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const filteredVariables = variables.filter((v) => v.key.trim() !== '');
        router.put(`/sites/${site.id}/environment`, { variables: filteredVariables });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <SettingsIcon className="h-5 w-5" />
                    Environment Variables
                </CardTitle>
                <CardDescription>Manage environment variables for your application. These will be synced to the .env file on your server.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-3">
                        {variables.map((variable, index) => (
                            <div key={index} className="flex gap-3">
                                <div className="flex-1">
                                    <Input
                                        placeholder="VARIABLE_NAME"
                                        value={variable.key}
                                        onChange={(e) => updateVariable(index, 'key', e.target.value.toUpperCase())}
                                        className="font-mono"
                                    />
                                </div>
                                <div className="flex-1">
                                    <Input
                                        placeholder="value"
                                        value={variable.value}
                                        onChange={(e) => updateVariable(index, 'value', e.target.value)}
                                    />
                                </div>
                                <Button type="button" variant="ghost" size="icon" onClick={() => removeVariable(index)}>
                                    <XIcon className="h-4 w-4" />
                                </Button>
                            </div>
                        ))}
                    </div>

                    <Button type="button" variant="outline" size="sm" onClick={addVariable}>
                        <PlusIcon className="mr-2 h-4 w-4" />
                        Add Variable
                    </Button>

                    <Separator />

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                            Save & Sync
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function DeployScriptTab({ site }: { site: Props['site']['data'] }) {
    const form = useForm({
        script: site.deploy_script || '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/sites/${site.id}/deploy-script`);
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <FileCodeIcon className="h-5 w-5" />
                    Deploy Script
                </CardTitle>
                <CardDescription>
                    Customize the deployment script that runs when you deploy your site. Available variables: $SITE_ROOT, $BRANCH, $PHP, $COMPOSER, $PHP_FPM
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="script">Deployment Script</Label>
                        <Textarea
                            id="script"
                            value={form.data.script}
                            onChange={(e) => form.setData('script', e.target.value)}
                            className="min-h-[400px] font-mono text-sm"
                            placeholder="cd $SITE_ROOT&#10;git pull origin $BRANCH&#10;$COMPOSER install --no-dev"
                        />
                        {form.errors.script && <p className="text-destructive text-sm">{form.errors.script}</p>}
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                            Save Script
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
