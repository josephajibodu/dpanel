import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useSiteNginxUpdates } from '@/hooks/use-site-nginx-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Site } from '@/types/site';
import { Head, router, usePage } from '@inertiajs/react';
import Editor from '@monaco-editor/react';
import {
    ChevronDownIcon,
    ChevronRightIcon,
    ClockIcon,
    FileIcon,
    FilePlusIcon,
    GlobeIcon,
    LockIcon,
    PencilIcon,
    RefreshCwIcon,
    RotateCcwIcon,
    Trash2Icon,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';

interface NginxFile {
    id: number;
    ulid: string;
    site_domain_id: number | null;
    section: string;
    path: string;
    content: string | null;
    sync_status: string;
    sync_error: string | null;
}

interface NginxHistoryEntry {
    id: number;
    event: string;
    path: string;
    old_path: string | null;
    user_name: string | null;
    created_at: string;
    has_content: boolean;
}

interface NginxSection {
    value: string;
    label: string;
    description: string;
    read_only: boolean;
}

interface SiteDomainData {
    id: number;
    hostname: string;
    is_primary: boolean;
}

interface Props {
    server: { data: { id: number; name: string } };
    site: { data: Site & { server?: { id: number; name: string } } };
    nginxFiles: NginxFile[];
    nginxHistory: NginxHistoryEntry[];
    sections: NginxSection[];
    domains: SiteDomainData[];
}

const SYNC_STATUS_LABELS: Record<string, string> = {
    pending: 'Queued',
    syncing: 'Applying...',
    synced: 'Applied',
    failed: 'Failed',
};

const EVENT_LABELS: Record<string, string> = {
    created: 'Created',
    updated: 'Updated',
    deleted: 'Deleted',
    renamed: 'Renamed',
};

export default function SiteNginxIndex({
    server: serverProp,
    site: siteProp,
    nginxFiles,
    nginxHistory,
    sections,
    domains,
}: Props) {
    const { currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const { resolvedAppearance } = useAppearance();
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;

    useSiteNginxUpdates(site.id);

    const [selectedFile, setSelectedFile] = useState<NginxFile | null>(null);
    const [editorContent, setEditorContent] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [collapsedDomains, setCollapsedDomains] = useState<Set<number>>(new Set());
    const [collapsedSections, setCollapsedSections] = useState<Set<string>>(new Set());
    const [showHistoryPanel, setShowHistoryPanel] = useState(false);

    const [createDialog, setCreateDialog] = useState(false);
    const [createDomainId, setCreateDomainId] = useState('');
    const [createSection, setCreateSection] = useState('');
    const [createSubPath, setCreateSubPath] = useState('');
    const [createName, setCreateName] = useState('');
    const [createContent, setCreateContent] = useState('');
    const [isCreating, setIsCreating] = useState(false);

    const [renameDialog, setRenameDialog] = useState<NginxFile | null>(null);
    const [renameName, setRenameName] = useState('');
    const [isRenaming, setIsRenaming] = useState(false);

    const [deleteDialog, setDeleteDialog] = useState<NginxFile | null>(null);
    const [isRefreshing, setIsRefreshing] = useState(false);

    const editorTheme = resolvedAppearance === 'dark' ? 'nginx-dark' : 'nginx-light';

    useEffect(() => {
        if (selectedFile) {
            const fresh = nginxFiles.find((f) => f.id === selectedFile.id);
            if (fresh) {
                setSelectedFile(fresh);
            }
        }
    }, [nginxFiles]);

    const openFile = useCallback(
        (file: NginxFile) => {
            setSelectedFile(file);
            setEditorContent(file.content ?? '');
        },
        [],
    );

    const handleSave = () => {
        if (!selectedFile) return;
        setIsSaving(true);
        router.put(
            teamPath(`/servers/${serverId}/sites/${site.id}/nginx/${selectedFile.id}`),
            { content: editorContent },
            {
                preserveScroll: true,
                onFinish: () => setIsSaving(false),
            },
        );
    };

    const handleCreate = () => {
        setIsCreating(true);
        router.post(
            teamPath(`/servers/${serverId}/sites/${site.id}/nginx`),
            {
                domain_id: parseInt(createDomainId),
                section: createSection,
                name: createName,
                sub_path: createSubPath || undefined,
                content: createContent,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCreateDialog(false);
                    setCreateDomainId('');
                    setCreateSection('');
                    setCreateSubPath('');
                    setCreateName('');
                    setCreateContent('');
                },
                onFinish: () => setIsCreating(false),
            },
        );
    };

    const handleRename = () => {
        if (!renameDialog) return;
        setIsRenaming(true);
        router.patch(
            teamPath(`/servers/${serverId}/sites/${site.id}/nginx/${renameDialog.id}/rename`),
            { name: renameName },
            {
                preserveScroll: true,
                onSuccess: () => setRenameDialog(null),
                onFinish: () => setIsRenaming(false),
            },
        );
    };

    const handleDelete = () => {
        if (!deleteDialog) return;
        setIsDeleting(true);
        router.delete(
            teamPath(`/servers/${serverId}/sites/${site.id}/nginx/${deleteDialog.id}`),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteDialog(null);
                    if (selectedFile?.id === deleteDialog.id) {
                        setSelectedFile(null);
                        setEditorContent('');
                    }
                },
                onFinish: () => setIsDeleting(false),
            },
        );
    };

    const handleRefresh = () => {
        setIsRefreshing(true);
        router.post(
            teamPath(`/servers/${serverId}/sites/${site.id}/nginx/refresh`),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsRefreshing(false),
            },
        );
    };

    const handleRestore = (historyId: number) => {
        router.post(
            teamPath(`/servers/${serverId}/sites/${site.id}/nginx-history/${historyId}/restore`),
            {},
            { preserveScroll: true },
        );
    };

    const toggleDomain = (domainId: number) => {
        setCollapsedDomains((prev) => {
            const next = new Set(prev);
            if (next.has(domainId)) {
                next.delete(domainId);
            } else {
                next.add(domainId);
            }
            return next;
        });
    };

    const toggleSection = (key: string) => {
        setCollapsedSections((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const basename = (path: string) => path.split('/').pop() ?? path;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server?.name || site.server?.name || 'Server', href: teamPath(`/servers/${serverId}`) },
        { title: site.domain, href: teamPath(`/servers/${serverId}/sites/${site.id}`) },
        { title: 'Nginx', href: teamPath(`/servers/${serverId}/sites/${site.id}/nginx`) },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(currentTeam?.slug ?? '', String(serverId ?? ''), site.id)}
        >
            <Head title={`Nginx - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Nginx</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Manage nginx configuration snippets for {site.domain}.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleRefresh}
                            disabled={isRefreshing}
                            title="Sync file contents from server"
                        >
                            <RefreshCwIcon className={`mr-1.5 h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                            Refresh
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setShowHistoryPanel((v) => !v)}
                        >
                            <ClockIcon className="mr-1.5 h-4 w-4" />
                            History
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => setCreateDialog(true)}
                        >
                            <FilePlusIcon className="mr-1.5 h-4 w-4" />
                            New file
                        </Button>
                    </div>
                </div>

                <div className="flex flex-1 gap-4 overflow-hidden">
                    {/* File explorer — domain → section → files */}
                    <div className="w-64 shrink-0 overflow-y-auto rounded-lg border">
                        {domains.length === 0 && (
                            <p className="text-muted-foreground px-4 py-3 text-xs italic">
                                No domains configured yet.
                            </p>
                        )}
                        {domains.map((domain) => {
                            const isDomainCollapsed = collapsedDomains.has(domain.id);
                            const domainFiles = nginxFiles.filter((f) => f.site_domain_id === domain.id);

                            return (
                                <div key={domain.id} className="border-b last:border-b-0">
                                    <button
                                        onClick={() => toggleDomain(domain.id)}
                                        className="hover:bg-muted/50 flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-semibold"
                                    >
                                        {isDomainCollapsed ? (
                                            <ChevronRightIcon className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                                        ) : (
                                            <ChevronDownIcon className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                                        )}
                                        <GlobeIcon className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                                        <span className="flex-1 truncate font-mono text-xs">{domain.hostname}</span>
                                        {domain.is_primary && (
                                            <span className="text-muted-foreground text-xs">primary</span>
                                        )}
                                    </button>

                                    {!isDomainCollapsed && (
                                        <div className="pl-2">
                                            {sections.map((section) => {
                                                const sectionKey = `${domain.id}:${section.value}`;
                                                const isSectionCollapsed = collapsedSections.has(sectionKey);
                                                const sectionFiles = domainFiles.filter((f) => f.section === section.value);

                                                return (
                                                    <div key={section.value} className="border-b last:border-b-0">
                                                        <button
                                                            onClick={() => toggleSection(sectionKey)}
                                                            className="hover:bg-muted/50 flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium"
                                                        >
                                                            {isSectionCollapsed ? (
                                                                <ChevronRightIcon className="text-muted-foreground h-3 w-3 shrink-0" />
                                                            ) : (
                                                                <ChevronDownIcon className="text-muted-foreground h-3 w-3 shrink-0" />
                                                            )}
                                                            <span className="capitalize">{section.label}</span>
                                                            {sectionFiles.length > 0 && (
                                                                <span className="text-muted-foreground ml-auto text-xs">
                                                                    {sectionFiles.length}
                                                                </span>
                                                            )}
                                                        </button>

                                                        {!isSectionCollapsed && (
                                                            <div className="pb-1">
                                                                {sectionFiles.length === 0 && (
                                                                    <p className="text-muted-foreground px-4 py-1 text-xs italic">
                                                                        {section.read_only ? 'None yet.' : 'No files'}
                                                                    </p>
                                                                )}
                                                                {sectionFiles.map((file) => (
                                                                    <div
                                                                        key={file.id}
                                                                        className={`group flex items-center gap-1.5 px-4 py-1.5 text-sm ${
                                                                            selectedFile?.id === file.id
                                                                                ? 'bg-accent text-accent-foreground'
                                                                                : 'hover:bg-muted/50 cursor-pointer'
                                                                        }`}
                                                                        onClick={() => openFile(file)}
                                                                    >
                                                                        {section.read_only ? (
                                                                            <LockIcon className="text-muted-foreground h-3 w-3 shrink-0" />
                                                                        ) : (
                                                                            <FileIcon className="text-muted-foreground h-3 w-3 shrink-0" />
                                                                        )}
                                                                        <span className="flex-1 truncate font-mono text-xs">
                                                                            {file.path.replace(`${section.value}/`, '')}
                                                                        </span>
                                                                        {file.sync_status !== 'synced' && (
                                                                            <span className="shrink-0">
                                                                                <StatusBadge
                                                                                    status={file.sync_status}
                                                                                    label={SYNC_STATUS_LABELS[file.sync_status] ?? file.sync_status}
                                                                                />
                                                                            </span>
                                                                        )}
                                                                        {!section.read_only && (
                                                                            <div className="ml-auto hidden shrink-0 items-center gap-0.5 group-hover:flex">
                                                                                <button
                                                                                    onClick={(e) => {
                                                                                        e.stopPropagation();
                                                                                        setRenameDialog(file);
                                                                                        setRenameName(basename(file.path));
                                                                                    }}
                                                                                    className="text-muted-foreground hover:text-foreground rounded p-0.5"
                                                                                    title="Rename"
                                                                                >
                                                                                    <PencilIcon className="h-3 w-3" />
                                                                                </button>
                                                                                <button
                                                                                    onClick={(e) => {
                                                                                        e.stopPropagation();
                                                                                        setDeleteDialog(file);
                                                                                    }}
                                                                                    className="text-muted-foreground hover:text-destructive rounded p-0.5"
                                                                                    title="Delete"
                                                                                >
                                                                                    <Trash2Icon className="h-3 w-3" />
                                                                                </button>
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Editor pane */}
                    <div className="flex flex-1 flex-col overflow-hidden rounded-lg border">
                        {selectedFile ? (
                            <>
                                <div className="flex items-center justify-between border-b px-4 py-2.5">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-sm font-medium">
                                            {selectedFile.path}
                                        </span>
                                        {selectedFile.sync_status && selectedFile.sync_status !== 'synced' && (
                                            <StatusBadge
                                                status={selectedFile.sync_status}
                                                label={SYNC_STATUS_LABELS[selectedFile.sync_status] ?? selectedFile.sync_status}
                                            />
                                        )}
                                    </div>
                                    <Button
                                        size="sm"
                                        onClick={handleSave}
                                        disabled={isSaving}
                                    >
                                        {isSaving ? 'Saving…' : 'Save'}
                                    </Button>
                                </div>

                                {selectedFile.sync_status === 'failed' && selectedFile.sync_error && (
                                    <div className="bg-destructive/10 text-destructive border-b px-4 py-2 font-mono text-xs">
                                        <strong>Nginx error:</strong> {selectedFile.sync_error}
                                    </div>
                                )}

                                <div className="flex-1 overflow-hidden [&_.monaco-editor_.line-numbers]:text-right [&_.monaco-editor_.line-numbers]:text-xs">
                                    <Editor
                                        height="100%"
                                        language="nginx"
                                        value={editorContent}
                                        theme={editorTheme}
                                        onChange={(v) => setEditorContent(v ?? '')}
                                        beforeMount={(monaco) => {
                                            monaco.editor.defineTheme('nginx-dark', {
                                                base: 'vs-dark',
                                                inherit: true,
                                                rules: [],
                                                colors: {},
                                            });
                                            monaco.editor.defineTheme('nginx-light', {
                                                base: 'vs',
                                                inherit: true,
                                                rules: [],
                                                colors: {},
                                            });
                                        }}
                                        options={{
                                            minimap: { enabled: false },
                                            lineNumbers: 'on',
                                            scrollBeyondLastLine: false,
                                            wordWrap: 'off',
                                            automaticLayout: true,
                                            fontSize: 13,
                                            lineHeight: 20,
                                            tabSize: 4,
                                            padding: { top: 8, bottom: 8 },
                                            fontFamily: 'Menlo, Monaco, "Courier New", monospace',
                                        }}
                                    />
                                </div>
                            </>
                        ) : (
                            <div className="text-muted-foreground flex flex-1 flex-col items-center justify-center gap-2">
                                <FileIcon className="h-10 w-10 opacity-30" />
                                <p className="text-sm">Select a file to edit, or create a new one.</p>
                            </div>
                        )}
                    </div>

                    {/* History panel */}
                    {showHistoryPanel && (
                        <div className="flex w-72 shrink-0 flex-col overflow-hidden rounded-lg border">
                            <div className="flex items-center justify-between border-b px-3 py-2.5">
                                <span className="text-sm font-medium">History</span>
                                <button
                                    onClick={() => setShowHistoryPanel(false)}
                                    className="text-muted-foreground hover:text-foreground text-xs"
                                >
                                    Close
                                </button>
                            </div>
                            <div className="flex-1 overflow-y-auto">
                                {nginxHistory.length === 0 ? (
                                    <p className="text-muted-foreground px-3 py-4 text-center text-xs">
                                        No history yet.
                                    </p>
                                ) : (
                                    nginxHistory.map((entry) => (
                                        <div
                                            key={entry.id}
                                            className="border-b px-3 py-2.5 last:border-b-0"
                                        >
                                            <div className="mb-1 flex items-center gap-1.5">
                                                <span className="text-muted-foreground text-xs font-medium uppercase">
                                                    {EVENT_LABELS[entry.event] ?? entry.event}
                                                </span>
                                            </div>
                                            <p className="font-mono text-xs break-all">{entry.path}</p>
                                            {entry.old_path && (
                                                <p className="text-muted-foreground font-mono text-xs">
                                                    ← {entry.old_path}
                                                </p>
                                            )}
                                            <div className="mt-1.5 flex items-center justify-between">
                                                <span className="text-muted-foreground text-xs">
                                                    {entry.user_name ?? 'System'} ·{' '}
                                                    {new Date(entry.created_at).toLocaleString()}
                                                </span>
                                                {entry.has_content && entry.event !== 'deleted' && (
                                                    <button
                                                        onClick={() => handleRestore(entry.id)}
                                                        className="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs"
                                                        title="Restore this version"
                                                    >
                                                        <RotateCcwIcon className="h-3 w-3" />
                                                        Restore
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Create file dialog */}
            <Dialog open={createDialog} onOpenChange={setCreateDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New nginx file</DialogTitle>
                        <DialogDescription>
                            Create a configuration snippet in one of the nginx include sections.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label>Domain</Label>
                            <Select value={createDomainId} onValueChange={setCreateDomainId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose domain…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {domains.map((d) => (
                                        <SelectItem key={d.id} value={String(d.id)}>
                                            <span className="font-mono">{d.hostname}</span>
                                            {d.is_primary && (
                                                <span className="text-muted-foreground ml-2 text-xs">primary</span>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Section</Label>
                            <Select value={createSection} onValueChange={setCreateSection}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose section…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sections.filter((s) => !s.read_only).map((s) => (
                                        <SelectItem key={s.value} value={s.value}>
                                            <span className="font-medium">{s.label}</span>
                                            <span className="text-muted-foreground ml-2 text-xs">
                                                {s.description}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {createSection === 'locations' && (
                            <div className="space-y-2">
                                <Label>Sub-path (optional)</Label>
                                <Input
                                    value={createSubPath}
                                    onChange={(e) => setCreateSubPath(e.target.value)}
                                    placeholder="e.g. php"
                                    className="font-mono"
                                />
                                <p className="text-muted-foreground text-xs">
                                    Creates the file under locations/{createSubPath || 'example'}/{createName || 'file.conf'}
                                </p>
                            </div>
                        )}
                        <div className="space-y-2">
                            <Label>Filename</Label>
                            <Input
                                value={createName}
                                onChange={(e) => setCreateName(e.target.value)}
                                placeholder="e.g. performance.conf"
                                className="font-mono"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Content</Label>
                            <div className="overflow-hidden rounded-md border">
                                <Editor
                                    height="200px"
                                    language="nginx"
                                    theme={editorTheme}
                                    value={createContent}
                                    onChange={(v) => setCreateContent(v ?? '')}
                                    options={{
                                        minimap: { enabled: false },
                                        lineNumbers: 'off',
                                        scrollBeyondLastLine: false,
                                        automaticLayout: true,
                                        fontSize: 12,
                                        tabSize: 4,
                                        padding: { top: 4, bottom: 4 },
                                        fontFamily: 'Menlo, Monaco, "Courier New", monospace',
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreateDialog(false)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={handleCreate}
                            disabled={isCreating || !createDomainId || !createSection || !createName || !createContent}
                        >
                            {isCreating ? 'Creating…' : 'Create'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Rename dialog */}
            <Dialog open={!!renameDialog} onOpenChange={() => setRenameDialog(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Rename file</DialogTitle>
                        <DialogDescription>
                            Rename <span className="font-mono">{renameDialog?.path}</span>
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label>New filename</Label>
                        <Input
                            value={renameName}
                            onChange={(e) => setRenameName(e.target.value)}
                            placeholder="e.g. custom.conf"
                            className="font-mono"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRenameDialog(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={handleRename}
                            disabled={isRenaming || !renameName}
                        >
                            {isRenaming ? 'Renaming…' : 'Rename'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete confirmation dialog */}
            <Dialog open={!!deleteDialog} onOpenChange={() => setDeleteDialog(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete file</DialogTitle>
                        <DialogDescription>
                            Delete <span className="font-mono">{deleteDialog?.path}</span>? This will remove the file from the server and cannot be undone (history is kept).
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteDialog(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Deleting…' : 'Delete'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
