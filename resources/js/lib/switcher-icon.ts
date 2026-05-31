const SWITCHER_COLORS = [
    { bg: 'bg-red-500', text: 'text-white' },
    { bg: 'bg-teal-400', text: 'text-black' },
    { bg: 'bg-blue-500', text: 'text-white' },
    { bg: 'bg-violet-500', text: 'text-white' },
    { bg: 'bg-orange-500', text: 'text-white' },
    { bg: 'bg-emerald-500', text: 'text-white' },
    { bg: 'bg-pink-500', text: 'text-white' },
    { bg: 'bg-indigo-500', text: 'text-white' },
    { bg: 'bg-amber-500', text: 'text-black' },
    { bg: 'bg-cyan-500', text: 'text-black' },
] as const;

function hashString(value: string): number {
    let hash = 0;

    for (let index = 0; index < value.length; index++) {
        hash = value.charCodeAt(index) + ((hash << 5) - hash);
    }

    return Math.abs(hash);
}

export function getSwitcherInitials(label: string): string {
    const trimmed = label.trim();

    if (!trimmed) {
        return '?';
    }

    if (trimmed.includes('.')) {
        const subdomain = trimmed.split('.')[0] ?? trimmed;

        if (subdomain.length >= 2) {
            return subdomain.slice(0, 2).toLowerCase();
        }

        return subdomain.charAt(0).toUpperCase();
    }

    const parts = trimmed.split(/[\s\-_]+/).filter(Boolean);

    if (parts.length >= 2) {
        return `${parts[0].charAt(0)}${parts[1].charAt(0)}`.toUpperCase();
    }

    if (trimmed.length >= 2) {
        return trimmed.slice(0, 2).toUpperCase();
    }

    return trimmed.charAt(0).toUpperCase();
}

export function getSwitcherColor(label: string): (typeof SWITCHER_COLORS)[number] {
    return SWITCHER_COLORS[hashString(label.toLowerCase()) % SWITCHER_COLORS.length];
}
