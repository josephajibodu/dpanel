import { Head, useForm, usePage } from '@inertiajs/react';
import Editor, { type Monaco } from '@monaco-editor/react';
import { CircleNotchIcon, EyeIcon, LockIcon } from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useAppearance } from '@/hooks/use-appearance';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Site } from '@/types/site';

interface EnvironmentHistoryEntry {
    id: number;
    user_name: string | null;
    added_keys: string[];
    changed_keys: string[];
    removed_keys: string[];
    is_first_save: boolean;
    created_at: string;
}

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
        };
    };
    has_workers: boolean;
    history: EnvironmentHistoryEntry[];
    env_content?: string;
}

export default function SiteEnvironmentShow({
    server: serverProp,
    site: siteProp,
    has_workers = false,
    history = [],
    env_content,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const can = usePermission();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    const { resolvedAppearance } = useAppearance();
    const [revealed, setRevealed] = useState(false);
    const initialized = useRef(false);

    const form = useForm({
        env_content: '',
        clear_config_cache: false,
        restart_queue: false,
    });

    const setFormData = form.setData;

    // Sync deferred env_content into form once the deferred request resolves
    useEffect(() => {
        if (env_content !== undefined && !initialized.current) {
            setFormData('env_content', env_content);
            initialized.current = true;
        }
    }, [env_content, setFormData]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(teamPath(`/servers/${serverId}/sites/${site.id}/environment`));
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        {
            title: server?.name || site.server?.name || 'Server',
            href: teamPath(`/servers/${serverId}`),
        },
        {
            title: site.domain,
            href: teamPath(`/servers/${serverId}/sites/${site.id}`),
        },
        {
            title: 'Environment',
            href: teamPath(`/servers/${serverId}/sites/${site.id}/environment`),
        },
    ];

    const editorTheme =
        resolvedAppearance === 'dark' ? 'env-dark' : 'env-light';

    const handleEditorBeforeMount = (monaco: Monaco) => {
        if (
            !monaco.languages
                .getLanguages()
                .some((language: { id: string }) => language.id === 'dotenv')
        ) {
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

    const isLoading = revealed && env_content === undefined;

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(
                currentTeam?.slug ?? '',
                String(serverId ?? ''),
                site.id,
            )}
        >
            <Head title={`Environment - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Environment Variables
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Manage environment variables for your application. These
                        will be synced to the .env file on your server.
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <div className="relative overflow-hidden rounded-md border shadow-xs [&_.monaco-editor_.line-numbers]:text-right [&_.monaco-editor_.line-numbers]:text-xs">
                            <Editor
                                height="420px"
                                language="dotenv"
                                value={form.data.env_content}
                                beforeMount={handleEditorBeforeMount}
                                theme={editorTheme}
                                onChange={(value) =>
                                    form.setData('env_content', value ?? '')
                                }
                                options={{
                                    minimap: { enabled: false },
                                    lineNumbers: 'on',
                                    scrollBeyondLastLine: false,
                                    wordWrap: 'off',
                                    automaticLayout: true,
                                    fontSize: 13,
                                    lineHeight: 20,
                                    tabSize: 2,
                                    padding: { top: 24, bottom: 24 },
                                    fontFamily:
                                        'Menlo, Monaco, "Courier New", monospace',
                                    readOnly:
                                        !revealed ||
                                        env_content === undefined ||
                                        !can('deploy'),
                                }}
                            />

                            {!revealed && (
                                <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-md bg-background/90 backdrop-blur-xs">
                                    <LockIcon className="h-8 w-8 text-muted-foreground" />
                                    <p className="text-sm text-muted-foreground">
                                        Environment variables are hidden for
                                        security.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setRevealed(true)}
                                    >
                                        <EyeIcon className="h-4 w-4" />
                                        Reveal secrets
                                    </Button>
                                </div>
                            )}

                            {isLoading && (
                                <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-md bg-background/70 backdrop-blur-xs">
                                    <CircleNotchIcon className="h-6 w-6 animate-spin text-muted-foreground" />
                                    <p className="text-xs text-muted-foreground">
                                        Loading environment...
                                    </p>
                                </div>
                            )}
                        </div>
                        {form.errors.env_content && (
                            <p className="text-sm text-destructive">
                                {form.errors.env_content}
                            </p>
                        )}
                    </div>

                    {can('deploy') && (
                        <>
                            <div className="flex flex-col gap-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.clear_config_cache}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'clear_config_cache',
                                                !!checked,
                                            )
                                        }
                                    />
                                    Clear config cache
                                </label>
                                {has_workers && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.restart_queue}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'restart_queue',
                                                    !!checked,
                                                )
                                            }
                                        />
                                        Restart queue workers
                                    </label>
                                )}
                            </div>

                            <Separator />

                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        !revealed ||
                                        env_content === undefined
                                    }
                                >
                                    {form.processing && (
                                        <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                    )}
                                    Save & Sync
                                </Button>
                            </div>
                        </>
                    )}
                </form>

                <div className="space-y-3">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            Change history
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Who saved the environment, and which variables each
                            save added, changed or removed. Values are not shown
                            here.
                        </p>
                    </div>

                    {history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No changes recorded yet.
                        </p>
                    ) : (
                        <Card className="gap-0 divide-y py-0">
                            {history.map((entry) => (
                                <EnvironmentHistoryRow
                                    key={entry.id}
                                    entry={entry}
                                />
                            ))}
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function EnvironmentHistoryRow({ entry }: { entry: EnvironmentHistoryEntry }) {
    const keyGroups = [
        {
            keys: entry.added_keys,
            sign: '+',
            label: 'Added',
            className: 'text-emerald-600 dark:text-emerald-400',
        },
        {
            keys: entry.changed_keys,
            sign: '~',
            label: 'Changed',
            className: 'text-amber-600 dark:text-amber-400',
        },
        {
            keys: entry.removed_keys,
            sign: '−',
            label: 'Removed',
            className: 'text-red-600 dark:text-red-400',
        },
    ];
    const hasKeyChanges = keyGroups.some((group) => group.keys.length > 0);

    let summary = 'Only comments or blank lines changed';

    if (entry.is_first_save) {
        summary = 'First save from FlitOps';
    } else if (hasKeyChanges) {
        summary = keyGroups
            .filter((group) => group.keys.length > 0)
            .map((group) => `${group.label.toLowerCase()} ${group.keys.length}`)
            .join(', ');
        summary = summary.charAt(0).toUpperCase() + summary.slice(1);
    }

    return (
        <div className="flex flex-col gap-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm">
                    <span className="font-medium">
                        {entry.user_name ?? 'System'}
                    </span>
                    <span className="text-muted-foreground"> · {summary}</span>
                </p>
                <time
                    dateTime={entry.created_at}
                    className="text-xs text-muted-foreground"
                >
                    {new Date(entry.created_at).toLocaleString()}
                </time>
            </div>

            {hasKeyChanges && (
                <div className="flex flex-wrap gap-1.5">
                    {keyGroups.flatMap((group) =>
                        group.keys.map((key) => (
                            <Badge
                                key={`${group.label}-${key}`}
                                variant="outline"
                                className="font-mono"
                                title={group.label}
                            >
                                <span className={group.className}>
                                    {group.sign}
                                </span>
                                {key}
                            </Badge>
                        )),
                    )}
                </div>
            )}
        </div>
    );
}
