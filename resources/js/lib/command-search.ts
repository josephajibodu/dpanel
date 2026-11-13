import { type Icon } from '@phosphor-icons/react';

/** The kinds of thing the search box can jump to, in the order their groups are listed. */
export type SearchKind = 'site' | 'server' | 'database' | 'page';

export interface SearchItem {
    /** Stable id, used to remember recent picks. */
    key: string;
    kind: SearchKind;
    title: string;
    /** The site or server a page belongs to, shown before its title. */
    parent?: string;
    /** Muted extra detail, such as the server a site runs on. */
    detail?: string;
    href: string;
    /** Opens in a new tab instead of inside the app. */
    external?: boolean;
    icon?: Icon;
    /** Other words that should find this item. */
    keywords: string[];
    /** Words that find the parent site or server. */
    parentKeywords?: string[];
    /**
     * A page inside one site or server, such as its deployments. Shown only
     * when the search names the page, so a plain search for a site isn't
     * buried under every one of its pages. Adding the site or server's name
     * narrows it to that one.
     */
    isNested?: boolean;
    /** Belongs to the site or server the user is on right now. */
    isCurrent?: boolean;
}

export interface SearchGroup {
    kind: SearchKind;
    label: string;
    items: SearchItem[];
}

export const GROUP_LABELS: Record<SearchKind, string> = {
    site: 'Sites',
    server: 'Servers',
    database: 'Databases',
    page: 'Pages',
};

const GROUP_ORDER: SearchKind[] = ['site', 'server', 'database', 'page'];

/**
 * Optional shortcuts typed at the start of a search, such as `site:`, to
 * look through one kind of thing only.
 */
const SCOPE_PREFIXES: Record<string, SearchKind> = {
    site: 'site',
    sites: 'site',
    server: 'server',
    servers: 'server',
    db: 'database',
    database: 'database',
    databases: 'database',
    page: 'page',
    pages: 'page',
};

/** How many matches each group shows, so one big group can't push the others out of view. */
const GROUP_LIMIT = 8;

/** How many matches a group shows when the search is narrowed to it. */
const SCOPED_LIMIT = 50;

/** Bonus that lifts pages of the site or server the user is on above the same page elsewhere. */
const CURRENT_BONUS = 15;

/**
 * Splits `site: shop` into the kind it narrows to and the rest of the
 * search. Returns null when the search doesn't start with a known prefix.
 */
export function parseScopePrefix(
    query: string,
): { scope: SearchKind; rest: string } | null {
    const match = /^\s*([a-z]+):\s?(.*)$/i.exec(query);

    if (!match) {
        return null;
    }

    const scope = SCOPE_PREFIXES[match[1].toLowerCase()];

    return scope ? { scope, rest: match[2] } : null;
}

/**
 * Ranks the items for a search and groups them by kind. Groups are listed
 * best match first, so a search for a page name shows Pages at the top.
 * Each word typed must match something about an item for it to show.
 */
export function searchItems(
    items: SearchItem[],
    query: string,
    scope: SearchKind | null,
): SearchGroup[] {
    const words = query.toLowerCase().trim().split(/\s+/).filter(Boolean);
    const candidates = scope
        ? items.filter((item) => item.kind === scope)
        : items;

    // Narrowed but nothing typed yet: list everything of that kind.
    if (words.length === 0) {
        const listed = scope ? candidates.filter((item) => !item.isNested) : [];

        return toGroups(
            listed.map((item) => ({ item, score: 0 })),
            SCOPED_LIMIT,
        );
    }

    const matches = candidates
        .map((item) => ({ item, score: scoreItem(item, words) }))
        .filter((match) => match.score > 0)
        .sort((a, b) => b.score - a.score);

    return toGroups(matches, scope ? SCOPED_LIMIT : GROUP_LIMIT);
}

function toGroups(
    matches: { item: SearchItem; score: number }[],
    limit: number,
): SearchGroup[] {
    return GROUP_ORDER.map((kind) => {
        const ofKind = matches.filter((match) => match.item.kind === kind);

        return {
            kind,
            label: GROUP_LABELS[kind],
            items: ofKind.slice(0, limit).map((match) => match.item),
            best: ofKind[0]?.score ?? 0,
        };
    })
        .filter((group) => group.items.length > 0)
        .sort((a, b) => b.best - a.best)
        .map(({ kind, label, items }) => ({ kind, label, items }));
}

/** Zero means the item doesn't match. */
function scoreItem(item: SearchItem, words: string[]): number {
    const title = item.title.toLowerCase();
    const keywords = item.keywords.map((keyword) => keyword.toLowerCase());

    if (!item.isNested) {
        let total = 0;

        for (const word of words) {
            const score = scoreField(word, title, keywords);

            if (score === 0) {
                return 0;
            }

            total += score;
        }

        return total;
    }

    const parent = (item.parent ?? '').toLowerCase();
    const parentKeywords = (item.parentKeywords ?? []).map((keyword) =>
        keyword.toLowerCase(),
    );
    let total = 0;
    let wordsMatchingPage = 0;

    for (const word of words) {
        const pageScore = scoreField(word, title, keywords);
        const parentScore = scoreField(word, parent, parentKeywords);

        if (pageScore === 0 && parentScore === 0) {
            return 0;
        }

        wordsMatchingPage += pageScore > 0 ? 1 : 0;
        total += Math.max(pageScore, parentScore);
    }

    if (wordsMatchingPage === 0) {
        return 0;
    }

    return item.isCurrent ? total + CURRENT_BONUS : total;
}

/** How well one typed word matches an item's name, falling back to its other words. */
function scoreField(word: string, title: string, keywords: string[]): number {
    const titleScore = scoreText(word, title);

    if (titleScore > 0) {
        return titleScore;
    }

    const keywordScore = Math.max(
        0,
        ...keywords.map((keyword) => scoreText(word, keyword)),
    );

    if (keywordScore > 0) {
        return keywordScore / 2;
    }

    // Forgive a missing letter or two, as in "deplyments".
    return word.length >= 4 && isSubsequence(word, title) ? 10 : 0;
}

/** Exact beats starts-with, which beats starting a later word, which beats anywhere. */
function scoreText(word: string, text: string): number {
    if (text === word) {
        return 100;
    }

    if (text.startsWith(word)) {
        return 80;
    }

    let score = 0;
    let index = text.indexOf(word, 1);

    while (index !== -1) {
        if (/[\s._\-/:@]/.test(text[index - 1])) {
            return 60;
        }

        score = 40;
        index = text.indexOf(word, index + 1);
    }

    return score;
}

function isSubsequence(word: string, text: string): boolean {
    let position = 0;

    for (const character of text) {
        if (character === word[position]) {
            position++;

            if (position === word.length) {
                return true;
            }
        }
    }

    return false;
}
