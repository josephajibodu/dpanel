import { type IconProps as PhosphorIconProps } from '@phosphor-icons/react';
import { type ComponentType } from 'react';

import { cn } from '@/lib/utils';

interface IconProps extends Omit<PhosphorIconProps, 'ref'> {
    iconNode: ComponentType<PhosphorIconProps>;
}

export function Icon({
    iconNode: IconComponent,
    className,
    ...props
}: IconProps) {
    return <IconComponent className={cn('h-4 w-4', className)} {...props} />;
}
