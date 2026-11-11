import { MagnifyingGlassIcon } from '@phosphor-icons/react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface SearchInputProps {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    'aria-label': string;
    className?: string;
}

export function SearchInput({
    value,
    onChange,
    placeholder = 'Search...',
    className,
    ...props
}: SearchInputProps) {
    return (
        <div
            className={cn(
                'relative flex h-9 w-full items-center rounded-xl bg-card shadow-card focus-within:ring-2 focus-within:ring-ring',
                className,
            )}
        >
            <MagnifyingGlassIcon className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                type="search"
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-full border-0 bg-transparent pl-9 shadow-none hover:border-0 focus-visible:ring-0"
                {...props}
            />
        </div>
    );
}
