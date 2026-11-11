import { CheckIcon, CircleIcon, CircleNotchIcon } from '@phosphor-icons/react';

import { StatGroup } from '@/components/ui/stat-group';
import { cn } from '@/lib/utils';

interface ProvisioningStep {
    value: number;
    label: string;
    description: string;
}

interface ProvisioningStepTimelineProps {
    heading: string;
    waitingDescription: string;
    currentStep: { value: number; label: string; description: string } | null;
    steps: ProvisioningStep[];
    className?: string;
}

/**
 * A checklist of provisioning steps with a progress bar. Only the step in
 * progress shows its description, so the list stays short and scannable.
 */
export function ProvisioningStepTimeline({
    heading,
    waitingDescription,
    currentStep,
    steps,
    className,
}: ProvisioningStepTimelineProps) {
    const currentStepValue = currentStep?.value ?? 0;
    const completedCount = steps.filter(
        (step) => step.value < currentStepValue,
    ).length;
    const progress =
        steps.length > 0
            ? Math.round((completedCount / steps.length) * 100)
            : 0;

    return (
        <StatGroup className={className}>
            <div className="rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20">
                <div className="space-y-3 border-b p-5">
                    <div className="flex items-baseline justify-between gap-4">
                        <h2 className="text-sm font-medium">{heading}</h2>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {completedCount} of {steps.length} done
                        </span>
                    </div>
                    <div
                        role="progressbar"
                        aria-label={heading}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={progress}
                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full rounded-full bg-primary transition-[width] duration-500"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                    {currentStepValue === 0 && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <CircleNotchIcon
                                className="h-3.5 w-3.5 animate-spin"
                                aria-hidden
                            />
                            {waitingDescription}
                        </p>
                    )}
                </div>

                <ol className="space-y-1 p-3">
                    {steps.map((step) => {
                        const isCompleted = step.value < currentStepValue;
                        const isCurrent = step.value === currentStepValue;

                        return (
                            <li
                                key={step.value}
                                aria-current={isCurrent ? 'step' : undefined}
                                className={cn(
                                    'flex items-start gap-3 rounded-md px-2 py-1.5',
                                    isCurrent && 'bg-muted/60',
                                )}
                            >
                                <span className="flex h-5 w-4 shrink-0 items-center justify-center">
                                    {isCompleted ? (
                                        <CheckIcon className="h-4 w-4 text-green-600 dark:text-green-400" />
                                    ) : isCurrent ? (
                                        <CircleNotchIcon className="h-4 w-4 animate-spin text-primary" />
                                    ) : (
                                        <CircleIcon className="h-3 w-3 text-muted-foreground/50" />
                                    )}
                                </span>

                                <div className="min-w-0">
                                    <p
                                        className={cn(
                                            'text-sm',
                                            isCurrent && 'font-medium',
                                            !isCompleted &&
                                                !isCurrent &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {step.label}
                                    </p>
                                    {isCurrent && (
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {step.description}
                                        </p>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </div>
        </StatGroup>
    );
}
