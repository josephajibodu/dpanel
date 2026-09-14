import { StatusBadge, type StatusColor } from '@/components/status-badge';

interface ServerStatusBadgeProps {
    status: string;
    statusLabel: string;
    statusColor: StatusColor;
    className?: string;
}

const PULSING_STATUSES = ['pending', 'creating', 'provisioning', 'deleting'];

export function ServerStatusBadge({
    status,
    statusLabel,
    statusColor,
    className,
}: ServerStatusBadgeProps) {
    return (
        <StatusBadge
            status={statusLabel}
            color={statusColor}
            pulse={PULSING_STATUSES.includes(status)}
            className={className}
        />
    );
}
