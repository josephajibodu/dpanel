import { SearchIcon } from 'lucide-react';

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
        <div className={cn('relative w-full max-w-sm', className)}>
            <SearchIcon className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                type="search"
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="pl-9"
                {...props}
            />
        </div>
    );
}
