import { getSwitcherColor, getSwitcherInitials } from '@/lib/switcher-icon';
import { cn } from '@/lib/utils';

export function SwitcherIcon({
    label,
    className,
}: {
    label: string;
    className?: string;
}) {
    const initials = getSwitcherInitials(label);
    const color = getSwitcherColor(label);

    return (
        <span
            aria-hidden
            className={cn(
                'flex size-5 shrink-0 items-center justify-center rounded-[4px] text-[9px] font-semibold leading-none',
                color.bg,
                color.text,
                className,
            )}
        >
            {initials}
        </span>
    );
}
