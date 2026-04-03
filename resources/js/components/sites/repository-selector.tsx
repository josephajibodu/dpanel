import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { ChevronDownIcon, Loader2Icon, RefreshCwIcon, SearchIcon, XIcon } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

export interface Repository {
    id: number;
    name: string;
    full_name: string;
    ssh_url: string;
    html_url: string;
    default_branch: string;
    private: boolean;
}

interface RepositorySelectorProps {
    repositories: Repository[];
    loadingRepositories: boolean;
    onReload: () => void;
    value: string;
    onChange: (fullName: string, repo?: Repository) => void;
    disabled?: boolean;
    placeholder?: string;
    className?: string;
}

export function RepositorySelector({
    repositories,
    loadingRepositories,
    onReload,
    value,
    onChange,
    disabled = false,
    placeholder = 'Select a repository',
    className,
}: RepositorySelectorProps) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    const filteredRepositories = useMemo(() => {
        if (!search.trim()) {
            return repositories;
        }
        const term = search.toLowerCase();
        return repositories.filter(
            (repo) =>
                repo.full_name.toLowerCase().includes(term) ||
                repo.name.toLowerCase().includes(term),
        );
    }, [repositories, search]);

    const handleSelect = useCallback(
        (repo: Repository) => {
            onChange(repo.full_name, repo);
            setOpen(false);
            setSearch('');
        },
        [onChange],
    );

    const handleClear = useCallback(
        (e: React.MouseEvent | React.KeyboardEvent) => {
            e.stopPropagation();
            e.preventDefault();
            onChange('');
            setSearch('');
        },
        [onChange],
    );

    const handleReload = useCallback(
        (e: React.MouseEvent) => {
            e.preventDefault();
            e.stopPropagation();
            onReload();
        },
        [onReload],
    );

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    disabled={disabled}
                    className={cn(
                        'border-input focus-visible:ring-ring/50 flex h-9 w-full items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
                        value && 'ring-ring/50 border-primary',
                        className,
                    )}
                >
                    <span className="min-w-0 flex-1 truncate text-left">
                        {loadingRepositories ? (
                            <span className="text-muted-foreground flex items-center gap-2">
                                <Loader2Icon className="h-4 w-4 shrink-0 animate-spin" />
                                Loading repositories...
                            </span>
                        ) : value ? (
                            value
                        ) : (
                            <span className="text-muted-foreground">{placeholder}</span>
                        )}
                    </span>
                    <span className="flex shrink-0 items-center gap-1">
                        {value && !disabled && (
                            <span
                                role="button"
                                tabIndex={-1}
                                onClick={handleClear}
                                onKeyDown={(e) => e.key === 'Enter' && handleClear(e)}
                                className="hover:bg-muted rounded p-0.5"
                                aria-label="Clear selection"
                            >
                                <XIcon className="h-4 w-4 text-muted-foreground" />
                            </span>
                        )}
                        <ChevronDownIcon className="h-4 w-4 shrink-0 opacity-50" />
                    </span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-[var(--radix-dropdown-menu-trigger-width)] min-w-[16rem] p-0"
                onCloseAutoFocus={(e) => e.preventDefault()}
            >
                <div className="flex flex-col">
                    <div className="border-b p-2">
                        <div className="relative">
                            <SearchIcon className="text-muted-foreground absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2" />
                            <Input
                                placeholder="Search repositories"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="h-8 pl-8"
                                autoFocus
                            />
                        </div>
                    </div>
                    <div className="max-h-60 overflow-y-auto p-1">
                        {loadingRepositories ? (
                            <div className="flex items-center justify-center py-8 text-muted-foreground text-sm">
                                <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                Loading repositories...
                            </div>
                        ) : filteredRepositories.length > 0 ? (
                            filteredRepositories.map((repo) => (
                                <button
                                    key={repo.id}
                                    type="button"
                                    onClick={() => handleSelect(repo)}
                                    className={cn(
                                        'hover:bg-accent focus:bg-accent focus:text-accent-foreground flex w-full cursor-default items-center rounded-sm px-2 py-1.5 text-left text-sm outline-none',
                                        value === repo.full_name && 'bg-muted',
                                    )}
                                >
                                    {repo.full_name}
                                    {repo.private && (
                                        <span className="text-muted-foreground ml-1 text-xs">
                                            (private)
                                        </span>
                                    )}
                                </button>
                            ))
                        ) : (
                            <div className="py-6 text-center text-muted-foreground text-sm">
                                {search ? 'No repositories match your search.' : 'No repositories available.'}
                            </div>
                        )}
                    </div>
                    <div className="border-t p-1">
                        <button
                            type="button"
                            onClick={handleReload}
                            disabled={loadingRepositories}
                            className="hover:bg-accent focus:bg-accent focus:text-accent-foreground flex w-full cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-primary outline-none disabled:opacity-50"
                        >
                            <RefreshCwIcon
                                className={cn('h-4 w-4', loadingRepositories && 'animate-spin')}
                            />
                            Reload repositories
                        </button>
                    </div>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
