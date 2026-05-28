import { Head, useForm, usePage } from '@inertiajs/react';
import Editor, { type Monaco } from '@monaco-editor/react';
import { Loader2Icon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useAppearance } from '@/hooks/use-appearance';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type EnvironmentVariable, type Site } from '@/types/site';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
            environment_variables?: EnvironmentVariable[];
        };
    };
    has_workers: boolean;
    env_content?: string;
}

export default function SiteEnvironmentShow({ server: serverProp, site: siteProp, has_workers = false, env_content = '' }: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    const { resolvedAppearance } = useAppearance();
    const fallbackVariables: EnvironmentVariable[] = site.environment_variables ?? [];
    const initialContent = env_content !== ''
        ? env_content
        : fallbackVariables.length
        ? fallbackVariables.map((variable) => `${variable.key}=${variable.value ?? ''}`).join('\n')
        : '';

    const form = useForm({
        env_content: initialContent,
        clear_config_cache: false,
        restart_queue: false,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(teamPath(`/servers/${serverId}/sites/${site.id}/environment`));
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server?.name || site.server?.name || 'Server', href: teamPath(`/servers/${serverId}`) },
        { title: site.domain, href: teamPath(`/servers/${serverId}/sites/${site.id}`) },
        { title: 'Environment', href: teamPath(`/servers/${serverId}/sites/${site.id}/environment`) },
    ];

    const editorTheme = resolvedAppearance === 'dark' ? 'env-dark' : 'env-light';

    const handleEditorBeforeMount = (monaco: Monaco) => {
        if (! monaco.languages.getLanguages().some((language: { id: string }) => language.id === 'dotenv')) {
            monaco.languages.register({ id: 'dotenv' });
            monaco.languages.setMonarchTokensProvider('dotenv', {
                tokenizer: {
                    root: [
                        [/^\s*#.*/, 'comment'],
                        [/^\s*[A-Za-z_][A-Za-z0-9_]*(?=\s*=)/, 'key'],
                        [/=.+$/, 'value'],
                    ],
                },
            });
        }

        monaco.editor.defineTheme('env-dark', {
            base: 'vs-dark',
            inherit: true,
            rules: [
                { token: 'key', foreground: '9CDCFE' },
                { token: 'value', foreground: 'CE9178' },
                { token: 'comment', foreground: '6A9955', fontStyle: 'italic' },
            ],
            colors: {},
        });

        monaco.editor.defineTheme('env-light', {
            base: 'vs',
            inherit: true,
            rules: [
                { token: 'key', foreground: '0451A5' },
                { token: 'value', foreground: 'A31515' },
                { token: 'comment', foreground: '008000', fontStyle: 'italic' },
            ],
            colors: {},
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(currentTeam?.slug ?? '', String(serverId ?? ''), site.id)}
        >
            <Head title={`Environment - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Environment Variables
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Manage environment variables for your application. These will be synced to the .env file on your server.
                    </p>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="space-y-2">
                                <div className="overflow-hidden rounded-md border shadow-xs [&_.monaco-editor_.line-numbers]:text-right [&_.monaco-editor_.line-numbers]:text-xs">
                                    <Editor
                                        height="420px"
                                        language="dotenv"
                                        value={form.data.env_content}
                                        beforeMount={handleEditorBeforeMount}
                                        theme={editorTheme}
                                        onChange={(value) => form.setData('env_content', value ?? '')}
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
                                {form.errors.env_content && (
                                    <p className="text-destructive text-sm">{form.errors.env_content}</p>
                                )}
                            </div>

                            <div className="flex flex-col gap-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.clear_config_cache}
                                        onCheckedChange={(checked) =>
                                            form.setData('clear_config_cache', !!checked)
                                        }
                                    />
                                    Clear config cache
                                </label>
                                {has_workers && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.restart_queue}
                                            onCheckedChange={(checked) =>
                                                form.setData('restart_queue', !!checked)
                                            }
                                        />
                                        Restart queue workers
                                    </label>
                                )}
                            </div>

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
            </div>
        </AppLayout>
    );
}
