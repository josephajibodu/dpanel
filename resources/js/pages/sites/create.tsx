import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { PhpVersion, ProjectType, RepositoryProvider } from '@/types/site';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, GlobeIcon, Loader2Icon } from 'lucide-react';

interface Props {
    server: {
        data: Server;
    };
    projectTypes: ProjectType[];
    repositoryProviders: RepositoryProvider[];
    phpVersions: PhpVersion[];
}

export default function SitesCreate({ server, projectTypes, repositoryProviders, phpVersions }: Props) {
    const { data: serverData } = server;

    const form = useForm({
        server_id: serverData.id,
        domain: '',
        directory: '/public',
        repository: '',
        repository_provider: 'github',
        branch: 'main',
        project_type: 'laravel',
        php_version: serverData.php_version || '8.3',
        auto_deploy: false,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: serverData.name, href: `/servers/${serverData.id}` },
        { title: 'New Site', href: `/servers/${serverData.id}/sites/create` },
    ];

    function handleProjectTypeChange(value: string) {
        const projectType = projectTypes.find((pt) => pt.value === value);
        form.setData({
            ...form.data,
            project_type: value,
            directory: projectType?.defaultDirectory || '/public',
        });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/servers/${serverData.id}/sites`);
    }

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
                                <CardTitle className="flex items-center gap-2">
                                    <GlobeIcon className="h-5 w-5" />
                                    Domain Configuration
                                </CardTitle>
                                <CardDescription>Configure the domain and web root for your site.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
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
                                        Your site will be served from <code className="bg-muted rounded px-1">/home/artisan/{form.data.domain || 'example.com'}</code>
                                    </p>
                                </div>

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

                        {/* Repository Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Repository (Optional)</CardTitle>
                                <CardDescription>Connect a Git repository to enable deployments.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
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
                                    <p className="text-muted-foreground text-xs">Leave empty to create a site without a repository.</p>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Submit */}
                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" asChild>
                                <Link href={`/servers/${serverData.id}`}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={form.processing || !form.data.domain}>
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
