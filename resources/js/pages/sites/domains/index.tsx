import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useSiteDomainsUpdates } from '@/hooks/use-site-domains-updates';
import {
    CheckIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    CopyIcon,
    ExternalLinkIcon,
    GlobeIcon,
    Loader2Icon,
    LockIcon,
    MoreVerticalIcon,
    ShieldCheckIcon,
    ShieldXIcon,
} from 'lucide-react';
import { FormEvent, useState, type Dispatch, type SetStateAction } from 'react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Site } from '@/types/site';
import { type SiteDomain } from '@/types/site-domain';

interface Props {
    server: { data: { id: number; name: string } };
    site: { data: Site };
    domains: { data: SiteDomain[] };
    freeDomain: string;
}

const wwwOptions = [
    { value: 'from_www', label: 'Redirect from www', description: 'Send www to apex', recommended: true },
    { value: 'to_www', label: 'Redirect to www', description: 'Send apex to www' },
    { value: 'none', label: 'No redirect', description: 'Serve both independently' },
] as const;

export default function SiteDomainsIndex({ server: serverProp, site: siteProp, domains, freeDomain }: Props) {
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    const domainList = domains?.data ?? [];
    const { flash, currentTeam } = usePage<SharedData>().props;
    const teamPath = useTeamPath();

    useSiteDomainsUpdates(site.id);

    const [newHostname, setNewHostname] = useState('');
    const [hostnameError, setHostnameError] = useState<string | null>(null);
    const [validating, setValidating] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [expandedDns, setExpandedDns] = useState<Record<string, boolean>>({});
    const [domainToDelete, setDomainToDelete] = useState<SiteDomain | null>(null);

    const addForm = useForm({
        hostname: '',
        wildcard_enabled: false,
        www_redirect: 'from_www' as string,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server?.name || site.server?.name || 'Server', href: teamPath(`/servers/${serverId}`) },
        { title: site.domain, href: teamPath(`/servers/${serverId}/sites/${site.id}`) },
        { title: 'Domains', href: teamPath(`/servers/${serverId}/sites/${site.id}/domains`) },
    ];

    const systemDomains = domainList.filter((d) => d.type === 'system');
    const customDomains = domainList.filter((d) => d.type === 'custom');

    async function handleValidateAndOpenSheet(e: FormEvent) {
        e.preventDefault();
        setHostnameError(null);
        const trimmed = newHostname.trim().toLowerCase();
        if (!trimmed) {
            setHostnameError('Enter a domain name.');
            return;
        }

        setValidating(true);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        try {
            const res = await fetch(teamPath(`/servers/${serverId}/sites/${site.id}/domains/validate`), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ hostname: trimmed }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setHostnameError((data as { message?: string }).message || 'This domain cannot be added.');
                return;
            }
            addForm.setData({
                hostname: trimmed,
                wildcard_enabled: false,
                www_redirect: 'from_www',
            });
            setSheetOpen(true);
        } finally {
            setValidating(false);
        }
    }

    function submitAddDomain() {
        addForm.post(teamPath(`/servers/${serverId}/sites/${site.id}/domains`), {
            preserveScroll: true,
            onSuccess: () => {
                setSheetOpen(false);
                setNewHostname('');
            },
        });
    }

    function copyText(text: string) {
        void navigator.clipboard.writeText(text);
    }

    function visitDomain(hostname: string) {
        window.open(`https://${hostname}`, '_blank', 'noopener,noreferrer');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs} subNavItems={getSiteSubNavItems(currentTeam?.slug ?? '', String(serverId ?? ''), site.id)}>
            <Head title={`Domains — ${site.domain}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-8">
                <div className="flex flex-col gap-1">
                    <h1 className="text-foreground text-2xl font-semibold tracking-tight">Domains</h1>
                    <p className="text-muted-foreground text-sm">Manage your site&apos;s hostnames and DNS instructions.</p>
                </div>

                {flash?.success && (
                    <div className="bg-muted text-foreground rounded-lg border px-4 py-3 text-sm">{flash.success}</div>
                )}
                {flash?.error && (
                    <div className="border-destructive/50 bg-destructive/10 text-destructive rounded-lg border px-4 py-3 text-sm">
                        {flash.error}
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Flitops domain</CardTitle>
                        <CardDescription>Every site includes one free {freeDomain} hostname.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {systemDomains.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No system domain.</p>
                        ) : (
                            systemDomains.map((d) => (
                                <DomainRow
                                    key={d.ulid}
                                    domain={d}
                                    serverId={Number(serverId)}
                                    siteId={site.id}
                                    expandedDns={expandedDns}
                                    setExpandedDns={setExpandedDns}
                                    onCopy={copyText}
                                    onVisit={visitDomain}
                                    onDelete={setDomainToDelete}
                                    teamPath={teamPath}
                                />
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Custom domains</CardTitle>
                        <CardDescription>Add domains and aliases that you control at your DNS provider.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <form onSubmit={handleValidateAndOpenSheet} className="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <div className="flex-1 space-y-2">
                                <Input
                                    placeholder="your-domain.com"
                                    value={newHostname}
                                    onChange={(e) => {
                                        setNewHostname(e.target.value);
                                        setHostnameError(null);
                                    }}
                                    className={cn(hostnameError && 'border-destructive')}
                                />
                                {hostnameError && <p className="text-destructive text-sm">{hostnameError}</p>}
                            </div>
                            <Button type="submit" disabled={validating} className="shrink-0 sm:mt-0">
                                {validating ? <Loader2Icon className="size-4 animate-spin" /> : 'Add domain'}
                            </Button>
                        </form>

                        {customDomains.length === 0 ? (
                            <div className="border-muted-foreground/25 text-muted-foreground rounded-lg border border-dashed px-6 py-10 text-center">
                                <p className="text-foreground font-medium">No custom domains yet</p>
                                <p className="mt-1 text-sm">Add your first custom domain above.</p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {customDomains.map((d) => (
                                    <DomainRow
                                        key={d.ulid}
                                        domain={d}
                                        serverId={Number(serverId)}
                                        siteId={site.id}
                                        expandedDns={expandedDns}
                                        setExpandedDns={setExpandedDns}
                                        onCopy={copyText}
                                        onVisit={visitDomain}
                                        onDelete={setDomainToDelete}
                                        teamPath={teamPath}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent className="flex flex-col gap-0 overflow-y-auto sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>Add {addForm.data.hostname || 'domain'}</SheetTitle>
                        <SheetDescription>
                            This hostname will route to your site. Choose wildcard and www handling below.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex flex-1 flex-col gap-6 px-4 pb-4">
                        <div className="space-y-3">
                            <Label className="text-base font-medium">Wildcards</Label>
                            <p className="text-muted-foreground text-sm">Allow all subdomains to accept traffic.</p>
                            <div className="space-y-3">
                                <label className="flex cursor-pointer gap-3 rounded-md border p-3 has-[:checked]:border-primary">
                                    <input
                                        type="radio"
                                        name="wildcard"
                                        className="mt-1"
                                        checked={!addForm.data.wildcard_enabled}
                                        onChange={() => addForm.setData('wildcard_enabled', false)}
                                    />
                                    <span>
                                        <span className="font-medium">Off</span>
                                        <span className="text-muted-foreground block text-sm">Only this hostname.</span>
                                    </span>
                                </label>
                                <label className="flex cursor-pointer gap-3 rounded-md border p-3 has-[:checked]:border-primary">
                                    <input
                                        type="radio"
                                        name="wildcard"
                                        className="mt-1"
                                        checked={addForm.data.wildcard_enabled}
                                        onChange={() => addForm.setData('wildcard_enabled', true)}
                                    />
                                    <span>
                                        <span className="font-medium">On</span>
                                        <span className="text-muted-foreground block text-sm">
                                            Include *.hostname (same file, wildcard server_name).
                                        </span>
                                    </span>
                                </label>
                            </div>
                            {addForm.errors.wildcard_enabled && (
                                <p className="text-destructive text-sm">{addForm.errors.wildcard_enabled}</p>
                            )}
                        </div>

                        <div className="space-y-3">
                            <Label className="text-base font-medium">Redirects</Label>
                            <p className="text-muted-foreground text-sm">How www should behave for this hostname.</p>
                            <div className="space-y-3">
                                {wwwOptions.map((opt) => (
                                    <label
                                        key={opt.value}
                                        className="flex cursor-pointer gap-3 rounded-md border p-3 has-[:checked]:border-primary"
                                    >
                                        <input
                                            type="radio"
                                            name="www_redirect"
                                            className="mt-1"
                                            checked={addForm.data.www_redirect === opt.value}
                                            onChange={() => addForm.setData('www_redirect', opt.value)}
                                        />
                                        <span className="flex flex-1 flex-wrap items-center gap-2">
                                            <span className="font-medium">{opt.label}</span>
                                            {opt.recommended && (
                                                <span className="bg-primary/15 text-primary rounded px-1.5 py-0.5 text-xs">
                                                    Recommended
                                                </span>
                                            )}
                                            <span className="text-muted-foreground block w-full text-sm">{opt.description}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {addForm.errors.www_redirect && (
                                <p className="text-destructive text-sm">{addForm.errors.www_redirect}</p>
                            )}
                        </div>
                    </div>

                    <SheetFooter className="border-t p-4">
                        <Button
                            type="button"
                            className="w-full"
                            disabled={addForm.processing}
                            onClick={() => submitAddDomain()}
                        >
                            {addForm.processing ? <Loader2Icon className="size-4 animate-spin" /> : 'Add domain'}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            <ConfirmDialog
                open={domainToDelete !== null}
                onOpenChange={(open) => { if (!open) setDomainToDelete(null); }}
                title={`Remove ${domainToDelete?.hostname ?? 'domain'}?`}
                description="This will remove the domain from this site and clean up its nginx configuration."
                confirmLabel="Remove"
                variant="destructive"
                onConfirm={() => {
                    if (!domainToDelete) return;
                    router.delete(teamPath(`/servers/${serverId}/sites/${site.id}/domains/${domainToDelete.ulid}`), {
                        onFinish: () => setDomainToDelete(null),
                    });
                }}
            />
        </AppLayout>
    );
}

function DomainRow({
    domain,
    serverId,
    siteId,
    expandedDns,
    setExpandedDns,
    onCopy,
    onVisit,
    onDelete,
    teamPath,
}: {
    domain: SiteDomain;
    serverId: number;
    siteId: number | string;
    expandedDns: Record<string, boolean>;
    setExpandedDns: Dispatch<SetStateAction<Record<string, boolean>>>;
    onCopy: (s: string) => void;
    onVisit: (hostname: string) => void;
    onDelete: (domain: SiteDomain) => void;
    teamPath: (path: string) => string;
}) {
    const open = expandedDns[domain.ulid] ?? false;
    const hasDns = domain.dns_records.length > 0;
    const isDeleting = domain.status === 'deleting';

    const isCustomUnverified = domain.type === 'custom' && !domain.is_verified;

    return (
        <div className={cn('border-border bg-card rounded-lg border', isDeleting && 'opacity-60')}>
            <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <GlobeIcon className="text-muted-foreground size-4 shrink-0" />
                    <span className="truncate font-medium">{domain.hostname}</span>
                    {domain.type === 'system' && <LockIcon className="text-muted-foreground size-3.5 shrink-0" aria-hidden />}
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {isDeleting && (
                        <span className="border-destructive/40 bg-destructive/10 text-destructive inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium">
                            <Loader2Icon className="size-3 animate-spin" /> Deleting
                        </span>
                    )}
                    {!isDeleting && domain.type === 'custom' && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium',
                                domain.is_verified
                                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : 'border-amber-500/40 bg-amber-500/10 text-amber-600 dark:text-amber-400',
                            )}
                        >
                            {domain.is_verified ? (
                                <>
                                    <ShieldCheckIcon className="size-3" /> Verified
                                </>
                            ) : (
                                <>
                                    <ShieldXIcon className="size-3" /> Unverified
                                </>
                            )}
                        </span>
                    )}
                    {!isDeleting && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium',
                                domain.is_enabled
                                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : 'border-muted-foreground/40 text-muted-foreground',
                            )}
                        >
                            {domain.is_enabled ? (
                                <>
                                    <CheckIcon className="size-3" /> Enabled
                                </>
                            ) : (
                                'Disabled'
                            )}
                        </span>
                    )}
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild disabled={isDeleting}>
                        <Button variant="ghost" size="icon" className="size-8 shrink-0" disabled={isDeleting}>
                            <MoreVerticalIcon className="size-4" />
                            <span className="sr-only">Actions</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => onVisit(domain.hostname)}>
                            <ExternalLinkIcon className="mr-2 size-4" /> Visit
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => onCopy(`https://${domain.hostname}`)}>
                            <CopyIcon className="mr-2 size-4" /> Copy URL
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => onCopy(domain.ulid)}>
                            <CopyIcon className="mr-2 size-4" /> Copy ID
                        </DropdownMenuItem>
                        {hasDns && (
                            <DropdownMenuItem
                                onClick={() =>
                                    setExpandedDns((prev) => ({
                                        ...prev,
                                        [domain.ulid]: !prev[domain.ulid],
                                    }))
                                }
                            >
                                <GlobeIcon className="mr-2 size-4" /> View DNS records
                            </DropdownMenuItem>
                        )}
                        {domain.type === 'custom' && !domain.is_verified && (
                            <DropdownMenuItem
                                onClick={() =>
                                    router.post(teamPath(`/servers/${serverId}/sites/${siteId}/domains/${domain.ulid}/verify`))
                                }
                            >
                                <ShieldCheckIcon className="mr-2 size-4" /> Verify domain
                            </DropdownMenuItem>
                        )}
                        {domain.type === 'custom' && !domain.is_primary && domain.is_enabled && (
                            <DropdownMenuItem
                                onClick={() =>
                                    router.post(teamPath(`/servers/${serverId}/sites/${siteId}/domains/${domain.ulid}/primary`))
                                }
                            >
                                Set as primary
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onClick={() =>
                                router.patch(teamPath(`/servers/${serverId}/sites/${siteId}/domains/${domain.ulid}`), {
                                    is_enabled: !domain.is_enabled,
                                })
                            }
                        >
                            {domain.is_enabled ? 'Disable' : 'Enable'}
                        </DropdownMenuItem>
                        {domain.type === 'custom' && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    className="text-destructive"
                                    onClick={() => onDelete(domain)}
                                >
                                    Delete
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {isCustomUnverified && (
                <div className="border-t px-4 py-3">
                    <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
                        <ShieldXIcon className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <p className="text-sm text-amber-800 dark:text-amber-200">
                            Point your DNS A record to this server&apos;s IP, then use{' '}
                            <span className="font-medium">Verify domain</span> in the menu. Once verified, SSL will be issued automatically.
                        </p>
                    </div>
                </div>
            )}

            {hasDns && (
                <Collapsible open={open} onOpenChange={(v) => setExpandedDns((prev) => ({ ...prev, [domain.ulid]: v }))}>
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground flex w-full items-center justify-center gap-1 border-t px-4 py-2 text-sm"
                        >
                            DNS records
                            {open ? <ChevronUpIcon className="size-4" /> : <ChevronDownIcon className="size-4" />}
                        </button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div className="border-t px-4 pb-4 pt-2">
                            <p className="text-muted-foreground mb-3 text-sm">
                                Add these records at your DNS provider so traffic reaches this server.
                            </p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Value</TableHead>
                                        <TableHead className="w-[80px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {domain.dns_records.map((row, i) => (
                                        <TableRow key={`${row.type}-${row.name}-${i}`}>
                                            <TableCell className="font-mono text-xs">{row.type}</TableCell>
                                            <TableCell className="font-mono text-xs">
                                                <span className="mr-1">{row.name}</span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-6"
                                                    onClick={() => onCopy(row.name)}
                                                >
                                                    <CopyIcon className="size-3" />
                                                </Button>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                <span className="mr-1 break-all">{row.value}</span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-6"
                                                    onClick={() => onCopy(row.value)}
                                                >
                                                    <CopyIcon className="size-3" />
                                                </Button>
                                            </TableCell>
                                            <TableCell />
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <p className="text-muted-foreground mt-3 text-xs">
                                DNS changes can take a few minutes to propagate.
                            </p>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </div>
    );
}
