import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';

import { FormSection, SummaryCard } from '@/components/create-form';
import InputError from '@/components/input-error';
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
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Server } from '@/types/server';
import { PhpVersion, Site } from '@/types/site';

interface Props {
    server: { data: Server };
    site: { data: Site };
    phpVersions: PhpVersion[];
    supportsDeployOnPush: boolean;
}

export default function SitesEdit({
    server,
    site,
    phpVersions,
    supportsDeployOnPush,
}: Props) {
    const teamPath = useTeamPath();
    const can = usePermission();
    const serverData = server.data;
    const siteData = site.data;
    const sitePath = teamPath(`/servers/${serverData.id}/sites/${siteData.id}`);
    const canDeploy = can('deploy');

    const form = useForm({
        branch: siteData.branch,
        directory: siteData.directory || '/',
        php_version: siteData.php_version,
        auto_deploy: siteData.auto_deploy,
    });

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
                        {siteData.repository && (
                            <FormSection
                                title="Branch"
                                description={`The branch of ${siteData.repository} that deployments pull from.`}
                            >
                                <div className="space-y-2 sm:max-w-sm">
                                    <Input
                                        id="branch"
                                        aria-label="Branch"
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
                            </FormSection>
                        )}

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

                        {canDeploy && (
                            <FormSection
                                title="Deploy on push"
                                description={
                                    supportsDeployOnPush
                                        ? `Deploy automatically whenever someone pushes to ${form.data.branch}. A webhook is added to the repository on ${siteData.repository_provider_label}.`
                                        : 'Available for sites with a repository from a connected GitHub, GitLab or Bitbucket account.'
                                }
                                action={
                                    <Switch
                                        aria-label="Deploy on push"
                                        checked={form.data.auto_deploy}
                                        disabled={
                                            !supportsDeployOnPush &&
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
                            { label: 'Repository', value: siteData.repository },
                            {
                                label: 'Branch',
                                value: siteData.repository
                                    ? form.data.branch
                                    : null,
                            },
                            {
                                label: 'Type',
                                value: siteData.project_type_label,
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
                        disabled={!form.isDirty}
                        cancelHref={sitePath}
                    />
                </form>
            </div>
        </AppLayout>
    );
}
