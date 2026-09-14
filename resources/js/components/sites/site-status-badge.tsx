import { StatusBadge, type StatusColor } from '@/components/status-badge';

interface SiteStatusBadgeProps {
    status: string;
    statusLabel: string;
    statusColor: StatusColor;
    className?: string;
}

const PULSING_STATUSES = ['installing', 'deploying', 'deleting'];

export function SiteStatusBadge({
    status,
    statusLabel,
    statusColor,
    className,
}: SiteStatusBadgeProps) {
    return (
        <StatusBadge
            status={statusLabel}
            color={statusColor}
            pulse={PULSING_STATUSES.includes(status)}
            className={className}
        />
    );
}
