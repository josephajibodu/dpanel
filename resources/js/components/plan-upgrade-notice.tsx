import { Link } from '@inertiajs/react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { pricing } from '@/routes';

/**
 * Shown in place of a control the team's plan doesn't include. There is no
 * checkout yet, so it points to the pricing page.
 */
export default function PlanUpgradeNotice({ message }: { message: string }) {
    return (
        <Alert>
            <AlertDescription>
                <p>
                    {message}{' '}
                    <Link
                        href={pricing()}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        See plans
                    </Link>
                </p>
            </AlertDescription>
        </Alert>
    );
}
