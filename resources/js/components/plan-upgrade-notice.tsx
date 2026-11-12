import { Link } from '@inertiajs/react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { usePlansHref } from '@/hooks/use-plan';

/**
 * Shown in place of a control the team's plan doesn't include, with a link
 * to the plans.
 */
export default function PlanUpgradeNotice({ message }: { message: string }) {
    const plansHref = usePlansHref();

    return (
        <Alert>
            <AlertDescription>
                <p>
                    {message}{' '}
                    <Link
                        href={plansHref}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        See plans
                    </Link>
                </p>
            </AlertDescription>
        </Alert>
    );
}
