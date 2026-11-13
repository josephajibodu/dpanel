import { router, usePage } from '@inertiajs/react';
import {
    ArrowUpRightIcon,
    CaretRightIcon,
    DatabaseIcon,
    GearIcon,
    MagnifyingGlassIcon,
    PlusIcon,
} from '@phosphor-icons/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import {
    createContext,
    type KeyboardEvent,
    type ReactNode,
    use,
    useEffect,
    useRef,
    useState,
} from 'react';

import { index as searchIndexRoute } from '@/actions/App/Http/Controllers/SearchController';
import { resolveCurrentServer } from '@/components/server-switcher';
import { resolveCurrentSite } from '@/components/site-switcher';
import { SwitcherIcon } from '@/components/switcher-icon';
import { DialogOverlay, DialogPortal } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    getServerSubNavItems,
    getSettingsSubNavItems,
    getSiteSubNavItems,
} from '@/config/sub-nav-items';
import { useMainNav } from '@/hooks/use-main-nav';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import {
    GROUP_LABELS,
    parseScopePrefix,
    type SearchGroup,
    type SearchItem,
    searchItems,
    type SearchKind,
} from '@/lib/command-search';
import { cn, toUrl } from '@/lib/utils';
import { type SharedData, type TeamPermission } from '@/types';
import { type Server } from '@/types/server';

interface SearchIndex {
    servers: (Pick<
        Server,
        | 'id'
        | 'name'
        | 'ip_address'
        | 'has_php'
        | 'supports_sites'
        | 'database_type'
        | 'accepts_connections'
    > & { provider: string })[];
    sites: {
        id: number;
        server_id: number;
        domain: string;
        aliases: string[];
        repository: string | null;
    }[];
    databases: { id: number; server_id: number; name: string }[];
}

/** The last list loaded for each team, shown straight away the next time search opens. */
const loadedIndexes = new Map<string, SearchIndex>();

const RECENT_LIMIT = 5;

/** The tabs across the top of search; null is All. */
const TABS: (SearchKind | null)[] = [
    null,
    'site',
    'server',
    'database',
    'page',
];

const OpenCommandPaletteContext = createContext<() => void>(() => {});

/** Opens the search box, for buttons that offer it. */
export function useOpenCommandPalette(): () => void {
    return use(OpenCommandPaletteContext);
}

/**
 * Makes the search box available on every app page: Cmd+K (Ctrl+K on
 * Windows and Linux) opens it from anywhere, and buttons can open it with
 * `useOpenCommandPalette`.
 */
export function CommandPaletteProvider({ children }: { children: ReactNode }) {
    const [isOpen, setIsOpen] = useState(false);

    useEffect(() => {
        const toggleOnShortcut = (event: globalThis.KeyboardEvent) => {
            // Editors and the terminal use Cmd/Ctrl+K themselves and mark it handled.
            if (
                event.defaultPrevented ||
                event.key.toLowerCase() !== 'k' ||
                !(event.metaKey || event.ctrlKey) ||
                event.altKey ||
                event.shiftKey
            ) {
                return;
            }

            event.preventDefault();
            setIsOpen((open) => !open);
        };

        window.addEventListener('keydown', toggleOnShortcut);

        return () => window.removeEventListener('keydown', toggleOnShortcut);
    }, []);

    return (
        <OpenCommandPaletteContext value={() => setIsOpen(true)}>
            {children}

            <DialogPrimitive.Root open={isOpen} onOpenChange={setIsOpen}>
                <DialogPortal>
                    <DialogOverlay />
                    <DialogPrimitive.Content
                        aria-describedby={undefined}
                        className="fixed top-[12vh] left-1/2 z-50 flex max-h-[76vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 flex-col overflow-hidden rounded-2xl bg-background shadow-overlay duration-150 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
                    >
                        <DialogPrimitive.Title className="sr-only">
                            Search
                        </DialogPrimitive.Title>
                        <CommandPaletteBody onClose={() => setIsOpen(false)} />
                    </DialogPrimitive.Content>
                </DialogPortal>
            </DialogPrimitive.Root>
        </OpenCommandPaletteContext>
    );
}

function CommandPaletteBody({ onClose }: { onClose: () => void }) {
    const teamSlug = usePage<SharedData>().props.currentTeam?.slug ?? '';
    const { index, hasFailed } = useSearchIndex(teamSlug);
    const items = useSearchableItems(index);
    const [recentKeys] = useState(() => readRecentKeys(teamSlug));
    const [query, setQuery] = useState('');
    const [scope, setScope] = useState<SearchKind | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);

    const isBrowsing = query.trim() === '' && scope === null;
    const groups = isBrowsing
        ? startingGroups(items, recentKeys)
        : searchItems(items, query, scope);
    const options = groups.flatMap((group) => group.items);
    const activeOption = options[Math.min(activeIndex, options.length - 1)];
    const isLoading = index === null && !hasFailed;
    const needsIndex = !isBrowsing && scope !== 'page';

    useEffect(() => {
        listRef.current
            ?.querySelector('[aria-selected="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeOption]);

    const changeScope = (kind: SearchKind | null) => {
        setScope(kind);
        setActiveIndex(0);
    };

    const updateQuery = (value: string) => {
        // Typing `sites:` and the like jumps to that tab.
        const prefix = parseScopePrefix(value);

        if (prefix) {
            setScope(prefix.scope);
            setQuery(prefix.rest);
        } else {
            setQuery(value);
        }

        setActiveIndex(0);
    };

    const openItem = (item: SearchItem, inNewTab: boolean) => {
        rememberRecentKey(teamSlug, item.key);
        onClose();

        if (item.external || inNewTab) {
            window.open(item.href, '_blank', 'noopener,noreferrer');

            return;
        }

        router.visit(item.href);
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (options.length === 0) {
                return;
            }

            const current = options.indexOf(activeOption);
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActiveIndex((current + step + options.length) % options.length);

            return;
        }

        if (event.key === 'Enter' && activeOption) {
            event.preventDefault();
            openItem(activeOption, event.metaKey || event.ctrlKey);

            return;
        }

        if (event.key === 'Tab') {
            event.preventDefault();

            const step = event.shiftKey ? -1 : 1;
            const next =
                (TABS.indexOf(scope) + step + TABS.length) % TABS.length;
            changeScope(TABS[next]);
        }
    };

    let optionNumber = -1;

    return (
        <>
            <div className="flex h-13 shrink-0 items-center gap-2.5 px-4">
                <MagnifyingGlassIcon className="size-[18px] shrink-0 text-muted-foreground" />

                <input
                    autoFocus
                    type="text"
                    role="combobox"
                    aria-expanded
                    aria-controls="command-palette-results"
                    aria-activedescendant={
                        activeOption
                            ? `command-palette-option-${activeOption.key}`
                            : undefined
                    }
                    aria-label="Search sites, servers and pages"
                    autoComplete="off"
                    spellCheck={false}
                    value={query}
                    onChange={(event) => updateQuery(event.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder={
                        scope
                            ? `Search ${GROUP_LABELS[scope].toLowerCase()}…`
                            : 'Search sites, servers and pages…'
                    }
                    className="h-full min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>

            <div
                role="tablist"
                aria-label="Show only"
                className="flex shrink-0 gap-1 overflow-x-auto border-b px-3 pb-2.5"
            >
                {TABS.map((kind) => (
                    <button
                        key={kind ?? 'all'}
                        type="button"
                        role="tab"
                        aria-selected={scope === kind}
                        aria-controls="command-palette-results"
                        // Focus stays in the search box; Tab switches tabs from there.
                        tabIndex={-1}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => changeScope(kind)}
                        className={cn(
                            'h-7 shrink-0 rounded-lg px-2.5 text-[13px] font-medium transition-colors',
                            scope === kind
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {kind ? GROUP_LABELS[kind] : 'All'}
                    </button>
                ))}
            </div>

            <div
                ref={listRef}
                id="command-palette-results"
                role="listbox"
                aria-label="Results"
                className="min-h-0 flex-1 overflow-y-auto p-2"
            >
                {groups.map((group) => (
                    <div
                        key={group.label}
                        role="group"
                        aria-label={group.label}
                        className="not-first:mt-2"
                    >
                        {/* On a tab, its name already says what's listed. */}
                        {scope === null && (
                            <div className="px-2 pt-1 pb-1.5 text-xs font-medium text-muted-foreground">
                                {group.label}
                            </div>
                        )}

                        {group.items.map((item) => {
                            optionNumber++;
                            const position = optionNumber;

                            return (
                                <ResultRow
                                    key={item.key}
                                    item={item}
                                    isActive={item === activeOption}
                                    onHover={() => setActiveIndex(position)}
                                    onOpen={(inNewTab) =>
                                        openItem(item, inNewTab)
                                    }
                                />
                            );
                        })}
                    </div>
                ))}

                {needsIndex && isLoading && (
                    <div className="flex items-center gap-2 px-2 py-2 text-[13px] text-muted-foreground">
                        <Spinner className="size-3.5" />
                        Loading your sites and servers…
                    </div>
                )}

                {needsIndex && hasFailed && (
                    <p className="px-2 py-2 text-[13px] text-muted-foreground">
                        Couldn't load your sites and servers. Close this and try
                        again in a moment.
                    </p>
                )}

                {options.length === 0 && !(needsIndex && isLoading) && (
                    <div className="px-2 py-8 text-center">
                        <p className="text-sm font-medium">
                            {query.trim()
                                ? `Nothing matches “${query.trim()}”`
                                : `No ${GROUP_LABELS[scope ?? 'page'].toLowerCase()} yet`}
                        </p>
                        {query.trim() && (
                            <p className="mt-1 text-[13px] text-muted-foreground">
                                Try a site's domain, a server's name or IP
                                address, or a page such as Billing.
                            </p>
                        )}
                    </div>
                )}
            </div>

            {/* Keyboard hints; phones have no keys to press. */}
            <div className="hidden shrink-0 items-center gap-4 border-t px-4 py-2 text-xs text-muted-foreground md:flex">
                <span className="flex items-center gap-1.5">
                    <Key>↑</Key>
                    <Key>↓</Key>
                    to move
                </span>
                <span className="flex items-center gap-1.5">
                    <Key>↵</Key>
                    to open
                </span>
                <span className="flex items-center gap-1.5">
                    <Key>tab</Key>
                    to switch
                </span>
                <span className="flex items-center gap-1.5">
                    <Key>esc</Key>
                    to close
                </span>
            </div>
        </>
    );
}

function ResultRow({
    item,
    isActive,
    onHover,
    onOpen,
}: {
    item: SearchItem;
    isActive: boolean;
    onHover: () => void;
    onOpen: (inNewTab: boolean) => void;
}) {
    return (
        <div
            id={`command-palette-option-${item.key}`}
            role="option"
            aria-selected={isActive}
            // Mouse moves, not enters, so scrolling with the arrow keys
            // doesn't hand the selection to whatever row slides under the pointer.
            onMouseMove={isActive ? undefined : onHover}
            onClick={(event) => onOpen(event.metaKey || event.ctrlKey)}
            className={cn(
                'flex h-9 cursor-pointer items-center gap-2.5 rounded-lg px-2 text-[13px]',
                isActive && 'bg-muted',
            )}
        >
            <ResultIcon item={item} />

            <span className="flex min-w-0 flex-1 items-center gap-1">
                {item.parent && (
                    <>
                        <span className="truncate text-muted-foreground">
                            {item.parent}
                        </span>
                        <CaretRightIcon className="size-3 shrink-0 text-muted-foreground" />
                    </>
                )}
                {/* With a parent, the parent gives way first so the page name stays whole. */}
                <span
                    className={cn(
                        'truncate font-medium',
                        item.parent && 'shrink-0',
                    )}
                >
                    {item.title}
                </span>
            </span>

            {item.detail && (
                <span className="hidden max-w-[45%] shrink-0 truncate text-xs text-muted-foreground sm:block">
                    {item.detail}
                </span>
            )}

            {item.external && (
                <ArrowUpRightIcon className="size-3.5 shrink-0 text-muted-foreground" />
            )}
        </div>
    );
}

function ResultIcon({ item }: { item: SearchItem }) {
    if (item.kind === 'site' || item.kind === 'server') {
        return <SwitcherIcon label={item.parent ?? item.title} />;
    }

    const Icon = item.kind === 'database' ? DatabaseIcon : item.icon;

    return (
        <span className="flex size-5 shrink-0 items-center justify-center text-muted-foreground">
            {Icon && <Icon className="size-[18px]" />}
        </span>
    );
}

function Key({ children }: { children: ReactNode }) {
    return (
        <kbd className="flex h-[18px] min-w-[18px] items-center justify-center rounded border bg-muted px-1 font-sans text-[11px] leading-none">
            {children}
        </kbd>
    );
}

/** What shows before anything is typed: recent picks, then every page. */
function startingGroups(
    items: SearchItem[],
    recentKeys: string[],
): SearchGroup[] {
    const byKey = new Map(items.map((item) => [item.key, item]));
    const recent = recentKeys
        .map((key) => byKey.get(key))
        .filter((item): item is SearchItem => item !== undefined)
        .slice(0, RECENT_LIMIT);
    const pages = items.filter(
        (item) => item.kind === 'page' && !recent.includes(item),
    );

    return [
        { kind: 'page' as const, label: 'Recent', items: recent },
        { kind: 'page' as const, label: 'Pages', items: pages },
    ].filter((group) => group.items.length > 0);
}

/**
 * Loads the team's servers, sites and databases each time search opens.
 * A list loaded earlier shows straight away while the fresh one loads.
 */
function useSearchIndex(teamSlug: string): {
    index: SearchIndex | null;
    hasFailed: boolean;
} {
    const [loaded, setLoaded] = useState(() => {
        const cached = loadedIndexes.get(teamSlug);

        return cached ? { teamSlug, index: cached } : null;
    });
    const [hasFailed, setHasFailed] = useState(false);

    useEffect(() => {
        if (!teamSlug) {
            return;
        }

        const controller = new AbortController();

        fetch(searchIndexRoute.url(teamSlug), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(
                        `Search list failed with ${response.status}`,
                    );
                }

                return response.json() as Promise<SearchIndex>;
            })
            .then((index) => {
                loadedIndexes.set(teamSlug, index);
                setLoaded({ teamSlug, index });
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setHasFailed(true);
                }
            });

        return () => controller.abort();
    }, [teamSlug]);

    const index = loaded?.teamSlug === teamSlug ? loaded.index : null;

    return { index, hasFailed: hasFailed && index === null };
}

/**
 * Everything search can find: the sidebar's pages, plus each site, server
 * and database in the team, and the pages inside each site and server.
 */
function useSearchableItems(index: SearchIndex | null): SearchItem[] {
    const { groups, documentation } = useMainNav();
    const teamPath = useTeamPath();
    const can = usePermission();
    const teamSlug = usePage<SharedData>().props.currentTeam?.slug ?? '';
    const pageProps = usePage().props as Record<string, unknown>;
    const currentServerId = resolveCurrentServer(pageProps.server)?.id;
    const currentSiteId = resolveCurrentSite(pageProps.site)?.id;

    const isAllowed = (permission?: TeamPermission) =>
        !permission || can(permission);

    const items: SearchItem[] = [];

    for (const navItem of groups.flatMap((group) => group.items)) {
        const href = toUrl(navItem.href);
        items.push({
            key: `page:${href}`,
            kind: 'page',
            title: navItem.title,
            href,
            icon: navItem.icon ?? undefined,
            keywords: navItem.keywords ?? [],
        });
    }

    if (can('manage_servers')) {
        items.push({
            key: 'page:create-server',
            kind: 'page',
            title: 'Create server',
            href: teamPath('/servers/create'),
            icon: PlusIcon,
            keywords: ['new server', 'add server', 'provision', 'launch'],
        });
    }

    for (const settingsItem of getSettingsSubNavItems()) {
        items.push({
            key: `page:${settingsItem.href}`,
            kind: 'page',
            title: settingsItem.title,
            parent: 'Settings',
            href: settingsItem.href,
            icon: GearIcon,
            keywords: ['settings', ...(settingsItem.keywords ?? [])],
        });
    }

    items.push({
        key: 'page:documentation',
        kind: 'page',
        title: documentation.title,
        href: toUrl(documentation.href),
        external: true,
        icon: documentation.icon ?? undefined,
        keywords: documentation.keywords ?? [],
    });

    if (!index) {
        return items;
    }

    const serversById = new Map(
        index.servers.map((server) => [server.id, server]),
    );

    for (const site of index.sites) {
        const serverName = serversById.get(site.server_id)?.name ?? '';
        const siteHref = teamPath(
            `/servers/${site.server_id}/sites/${site.id}`,
        );
        const siteKeywords = [
            'sites',
            serverName,
            ...site.aliases,
            ...(site.repository ? [site.repository] : []),
        ];

        items.push({
            key: `site:${site.id}`,
            kind: 'site',
            title: site.domain,
            detail: serverName,
            href: siteHref,
            keywords: siteKeywords,
        });

        for (const subItem of getSiteSubNavItems(
            teamSlug,
            site.server_id,
            site.id,
        )) {
            if (subItem.href === siteHref || !isAllowed(subItem.permission)) {
                continue;
            }

            items.push({
                key: `site:${site.id}:${subItem.href}`,
                kind: 'site',
                title: subItem.title,
                parent: site.domain,
                href: subItem.href,
                keywords: subItem.keywords ?? [],
                parentKeywords: siteKeywords,
                isNested: true,
                isCurrent: site.id === currentSiteId,
            });
        }
    }

    for (const server of index.servers) {
        const serverHref = teamPath(`/servers/${server.id}`);
        const serverKeywords = [
            'servers',
            server.provider,
            ...(server.ip_address ? [server.ip_address] : []),
        ];

        items.push({
            key: `server:${server.id}`,
            kind: 'server',
            title: server.name,
            detail: [server.ip_address, server.provider]
                .filter(Boolean)
                .join(' · '),
            href: serverHref,
            keywords: serverKeywords,
        });

        for (const subItem of getServerSubNavItems(teamSlug, server)) {
            if (subItem.href === serverHref || !isAllowed(subItem.permission)) {
                continue;
            }

            items.push({
                key: `server:${server.id}:${subItem.href}`,
                kind: 'server',
                title: subItem.title,
                parent: server.name,
                href: subItem.href,
                keywords: subItem.keywords ?? [],
                parentKeywords: serverKeywords,
                isNested: true,
                isCurrent: server.id === currentServerId,
            });
        }
    }

    for (const database of index.databases) {
        const serverName = serversById.get(database.server_id)?.name ?? '';

        items.push({
            key: `database:${database.id}`,
            kind: 'database',
            title: database.name,
            detail: serverName,
            href: teamPath(`/servers/${database.server_id}/databases`),
            keywords: ['databases', 'db', serverName],
        });
    }

    return items;
}

function recentStorageKey(teamSlug: string): string {
    return `search-recent:${teamSlug}`;
}

function readRecentKeys(teamSlug: string): string[] {
    try {
        const stored: unknown = JSON.parse(
            localStorage.getItem(recentStorageKey(teamSlug)) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored.filter((key): key is string => typeof key === 'string')
            : [];
    } catch {
        return [];
    }
}

function rememberRecentKey(teamSlug: string, key: string): void {
    // Keep a few spares, since some may point at things deleted since.
    const keys = [
        key,
        ...readRecentKeys(teamSlug).filter((recent) => recent !== key),
    ].slice(0, RECENT_LIMIT * 2);

    try {
        localStorage.setItem(recentStorageKey(teamSlug), JSON.stringify(keys));
    } catch {
        // Private browsing can refuse storage; search still works without recents.
    }
}
