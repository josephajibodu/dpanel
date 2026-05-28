import { Head, useForm, usePage } from '@inertiajs/react';
import Editor, { type Monaco } from '@monaco-editor/react';
import { Loader2Icon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useAppearance } from '@/hooks/use-appearance';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Site } from '@/types/site';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
            deploy_script?: string;
        };
    };
}

export default function SiteDeployScriptShow({ server: serverProp, site: siteProp }: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    const { resolvedAppearance } = useAppearance();

    const form = useForm({
        script: site.deploy_script ?? '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(teamPath(`/servers/${serverId}/sites/${site.id}/deploy-script`));
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server?.name || site.server?.name || 'Server', href: teamPath(`/servers/${serverId}`) },
        { title: site.domain, href: teamPath(`/servers/${serverId}/sites/${site.id}`) },
        { title: 'Deploy Script', href: teamPath(`/servers/${serverId}/sites/${site.id}/deploy-script`) },
    ];

    const editorTheme = resolvedAppearance === 'dark' ? 'shell-dark' : 'shell-light';

    const handleEditorBeforeMount = (monaco: Monaco) => {
        monaco.editor.defineTheme('shell-dark', {
            base: 'vs-dark',
            inherit: true,
            rules: [],
            colors: {},
        });

        monaco.editor.defineTheme('shell-light', {
            base: 'vs',
            inherit: true,
            rules: [],
            colors: {},
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(currentTeam?.slug ?? '', String(serverId ?? ''), site.id)}
        >
            <Head title={`Deploy Script - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Deploy Script
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Customize the deployment script that runs when you deploy your site. Available variables: $SITE_ROOT, $BRANCH, $PHP, $COMPOSER, $PHP_FPM
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="script">Deployment Script</Label>
                        <div className="overflow-hidden rounded-md border shadow-xs [&_.monaco-editor_.line-numbers]:text-right [&_.monaco-editor_.line-numbers]:text-xs">
                            <Editor
                                height="420px"
                                language="shell"
                                value={form.data.script}
                                beforeMount={handleEditorBeforeMount}
                                theme={editorTheme}
                                onChange={(value) => form.setData('script', value ?? '')}
                                options={{
                                    minimap: { enabled: false },
                                    lineNumbers: 'on',
                                    scrollBeyondLastLine: false,
                                    wordWrap: 'off',
                                    automaticLayout: true,
                                    fontSize: 13,
                                    lineHeight: 20,
                                    tabSize: 2,
                                    padding: { top: 8, bottom: 8 },
                                    fontFamily: 'Menlo, Monaco, "Courier New", monospace',
                                }}
                            />
                        </div>
                        {form.errors.script && <p className="text-destructive text-sm">{form.errors.script}</p>}
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                            Save Script
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
