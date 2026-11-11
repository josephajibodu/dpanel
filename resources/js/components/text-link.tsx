import { Link } from '@inertiajs/react';
import { ComponentProps } from 'react';

import { cn } from '@/lib/utils';

type LinkProps = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: LinkProps) {
    return (
        <Link
            className={cn(
                'rounded-sm text-link underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
