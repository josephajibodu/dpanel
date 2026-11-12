import { Link } from '@inertiajs/react';
import { type ComponentProps, type ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { pricing } from '@/routes';
import { type PlanLock } from '@/types';

/**
 * A button for something the plan doesn't include. It stays in place but
 * greyed out, names the plan that unlocks it, and on hover says why with a
 * link to the plans, so the limit is visible before anyone tries it.
 */
export default function PlanLockedButton({
    lock,
    variant,
    size,
    children,
}: {
    lock: PlanLock;
    variant?: ComponentProps<typeof Button>['variant'];
    size?: ComponentProps<typeof Button>['size'];
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {/* A disabled button ignores the pointer, so this carries the hover. */}
                <span tabIndex={0} className="inline-flex cursor-not-allowed">
                    <Button variant={variant} size={size} disabled>
                        {children}
                        <Badge variant="secondary">{lock.plan}</Badge>
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {lock.message}{' '}
                <Link href={pricing()} className="underline underline-offset-2">
                    See plans
                </Link>
            </TooltipContent>
        </Tooltip>
    );
}
