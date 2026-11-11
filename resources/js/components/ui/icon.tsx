import { type Icon as PhosphorIcon } from '@phosphor-icons/react';

interface IconProps {
    iconNode?: PhosphorIcon | null;
    className?: string;
}

export function Icon({ iconNode: IconComponent, className }: IconProps) {
    if (!IconComponent) {
        return null;
    }

    return <IconComponent className={className} />;
}
