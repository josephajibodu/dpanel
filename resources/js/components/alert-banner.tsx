import { Link, router, usePage } from '@inertiajs/react';
import { XIcon } from '@phosphor-icons/react';

import { Button } from '@/components/ui/button';
import { dismiss } from '@/routes/notifications';
import { type SharedData } from '@/types';

/**
 * Failure alerts (such as a failed backup) that stay at the top of every page
 * until the user dismisses them or the problem is fixed.
 */
export function AlertBanner() {
    const { alerts } = usePage<SharedData>().props;

    if (!alerts || alerts.length === 0) {
        return null;
    }

    const handleDismiss = (id: string) => {
        router.post(
            dismiss.url(id),
            {},
            { preserveScroll: true, preserveState: true, only: ['alerts'] },
        );
    };

    return (
        <div className="flex flex-col gap-2 px-4 pt-2">
            {alerts.map((alert) => (
                <div
                    key={alert.id}
                    role="alert"
                    className="flex items-start gap-3 rounded-xl border border-destructive/20 bg-[#fed1d7]/60 px-4 py-3 text-sm dark:border-destructive/40 dark:bg-destructive/15"
                >
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <p className="font-semibold text-destructive-foreground">
                            {alert.title}
                        </p>
                        {alert.message && (
                            <p className="text-foreground/80">
                                {alert.message}
                            </p>
                        )}
                        {alert.url && (
                            <Link
                                href={alert.url}
                                className="w-fit font-medium text-destructive-foreground underline underline-offset-4"
                            >
                                View details
                            </Link>
                        )}
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7 shrink-0 text-destructive-foreground hover:bg-destructive/15 hover:text-destructive-foreground"
                        onClick={() => handleDismiss(alert.id)}
                        aria-label="Dismiss alert"
                    >
                        <XIcon className="size-4" />
                    </Button>
                </div>
            ))}
        </div>
    );
}
