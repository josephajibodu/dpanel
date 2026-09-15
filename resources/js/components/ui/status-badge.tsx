import { StatusBadge, type StatusColor } from '@/components/status-badge';

const STATUS_COLORS: Record<string, StatusColor> = {
    active: 'green',
    ready: 'green',
    enabled: 'green',
    installed: 'green',
    synced: 'green',
    default: 'green',
    installing: 'blue',
    creating: 'blue',
    syncing: 'blue',
    starting: 'blue',
    stopping: 'blue',
    restarting: 'blue',
    pending: 'gray',
    stopped: 'gray',
    disabled: 'gray',
    failed: 'red',
    error: 'red',
    deleting: 'orange',
};

const PULSING_STATUSES = new Set([
    'installing',
    'creating',
    'syncing',
    'starting',
    'stopping',
    'restarting',
    'pending',
    'deleting',
]);

interface AutoStatusBadgeProps {
    status: string;
    label?: string;
}

/** Renders the canonical StatusBadge, inferring a color from a raw status string
 *  for callers that don't have a backend-supplied color (see STATUS_COLORS above). */
export function AutoStatusBadge({ status, label }: AutoStatusBadgeProps) {
    const color = STATUS_COLORS[status] ?? 'gray';
    const displayLabel = label ?? status.charAt(0).toUpperCase() + status.slice(1);

    return (
        <StatusBadge status={displayLabel} color={color} pulse={PULSING_STATUSES.has(status)} />
    );
}
