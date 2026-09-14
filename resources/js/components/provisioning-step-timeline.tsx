import { CheckCircle2Icon, CircleIcon, Loader2Icon } from 'lucide-react';

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

export function ProvisioningStepTimeline({
    heading,
    waitingDescription,
    currentStep,
    steps,
    className,
}: ProvisioningStepTimelineProps) {
    const currentStepValue = currentStep?.value ?? 0;
    const currentStepLabel = currentStep?.label ?? 'Pending';
    const currentStepDescription =
        currentStep?.description ?? waitingDescription;
    const isStarting = currentStepValue === 0;

    return (
        <div className={cn('rounded-lg border bg-card p-4', className)}>
            <div className="mb-4">
                <h3 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    {heading}
                    {isStarting && (
                        <Loader2Icon
                            className="h-4 w-4 animate-spin text-primary"
                            aria-hidden
                        />
                    )}
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    {currentStepLabel}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {currentStepDescription}
                </p>
            </div>

            <div className="space-y-3">
                {steps.map((step) => {
                    const isCompleted = step.value < currentStepValue;
                    const isCurrent = step.value === currentStepValue;

                    return (
                        <div
                            key={step.value}
                            className="flex items-start gap-3"
                        >
                            <div className="pt-0.5">
                                {isCompleted ? (
                                    <CheckCircle2Icon className="h-4 w-4 text-green-600 dark:text-green-400" />
                                ) : isCurrent ? (
                                    <Loader2Icon className="h-4 w-4 animate-spin text-primary" />
                                ) : (
                                    <CircleIcon className="h-4 w-4 text-muted-foreground" />
                                )}
                            </div>

                            <div className="min-w-0">
                                <p
                                    className={cn(
                                        'text-sm font-medium',
                                        isCurrent && 'text-foreground',
                                        isCompleted && 'text-foreground',
                                        !isCompleted &&
                                            !isCurrent &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {step.label}
                                </p>
                                <p
                                    className={cn(
                                        'mt-0.5 text-xs',
                                        isCurrent
                                            ? 'text-muted-foreground'
                                            : 'text-muted-foreground/80',
                                    )}
                                >
                                    {step.description}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
