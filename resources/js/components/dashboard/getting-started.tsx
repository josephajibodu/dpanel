import { Link } from '@inertiajs/react';
import { CheckIcon } from '@phosphor-icons/react';

import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardTitle } from '@/components/ui/card';
import { useTeamPath } from '@/hooks/use-team-path';
import { cn } from '@/lib/utils';

interface GettingStartedStep {
    title: string;
    description: string;
    done: boolean;
    href: string | null;
    actionLabel: string;
}

/**
 * The two ways to get a first server: have FlitOps create one on a cloud
 * provider, or connect a machine the team already runs.
 */
export function AddServerOptions() {
    const teamPath = useTeamPath();

    const options = [
        {
            title: 'Create a cloud server',
            description:
                'FlitOps creates the server on your cloud provider and sets it up for you.',
            href: teamPath('/servers/create'),
            actionLabel: 'Create server',
            docsHref: 'https://docs.flitops.com/servers/creating-servers',
        },
        {
            title: 'Connect your own server',
            description:
                'Bring any Ubuntu machine you can reach over SSH, from any host.',
            href: teamPath('/servers/create?mode=custom'),
            actionLabel: 'Connect server',
            docsHref: 'https://docs.flitops.com/servers/custom-servers',
        },
    ];

    return (
        <div className="grid gap-4 md:grid-cols-2">
            {options.map((option) => (
                <Card key={option.title} className="gap-4 px-6">
                    <div className="space-y-1.5">
                        <CardTitle>{option.title}</CardTitle>
                        <CardDescription>{option.description}</CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={option.href}>{option.actionLabel}</Link>
                        </Button>
                        <Button asChild variant="ghost">
                            <a
                                href={option.docsHref}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Learn more
                            </a>
                        </Button>
                    </div>
                </Card>
            ))}
        </div>
    );
}

/**
 * A checklist of the setup a team needs before its first site goes live.
 * Each step ticks itself off from the team's real data.
 */
export function GettingStartedChecklist({
    providerAccounts,
    sshKeys,
    sourceControlAccounts,
    servers,
    sites,
    firstServerId,
}: {
    providerAccounts: number;
    sshKeys: number;
    sourceControlAccounts: number;
    servers: number;
    sites: number;
    firstServerId: number | null;
}) {
    const teamPath = useTeamPath();

    const steps: GettingStartedStep[] = [
        {
            title: 'Connect a cloud provider',
            description:
                'Skip this if you only plan to connect your own servers.',
            done: providerAccounts > 0 || servers > 0,
            href: teamPath('/provider-accounts/create'),
            actionLabel: 'Connect',
        },
        {
            title: 'Add your SSH key',
            description: 'So you can log in to your servers from your machine.',
            done: sshKeys > 0,
            href: teamPath('/ssh-keys'),
            actionLabel: 'Add key',
        },
        {
            title: 'Connect GitHub, GitLab, or Bitbucket',
            description: 'So FlitOps can pull and deploy your code.',
            done: sourceControlAccounts > 0,
            href: teamPath('/source-control'),
            actionLabel: 'Connect',
        },
        {
            title: 'Create your first server',
            description: 'On a cloud provider, or one you already run.',
            done: servers > 0,
            href: teamPath('/servers/create'),
            actionLabel: 'Create',
        },
        {
            title: 'Deploy your first site',
            description: 'Point a repository at your server and ship it.',
            done: sites > 0,
            href:
                firstServerId !== null
                    ? teamPath(`/servers/${firstServerId}/sites/create`)
                    : null,
            actionLabel: 'Create site',
        },
    ];

    const completed = steps.filter((step) => step.done).length;

    return (
        <Card className="gap-0 py-0">
            <div className="flex items-baseline justify-between px-6 py-4">
                <CardTitle>Getting started</CardTitle>
                <span className="text-sm text-muted-foreground">
                    {completed} of {steps.length} done
                </span>
            </div>
            <ol>
                {steps.map((step, index) => (
                    <li
                        key={step.title}
                        className="flex items-center gap-4 border-t px-6 py-3"
                    >
                        <span
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs text-muted-foreground',
                                step.done &&
                                    'border-green-600/40 bg-green-500/15 text-green-600 dark:text-green-400',
                            )}
                        >
                            {step.done ? (
                                <CheckIcon className="size-3.5" />
                            ) : (
                                index + 1
                            )}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p
                                className={cn(
                                    'text-sm font-medium',
                                    step.done &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {step.title}
                            </p>
                            {!step.done && (
                                <p className="text-sm text-muted-foreground">
                                    {step.description}
                                </p>
                            )}
                        </div>
                        {!step.done && step.href && (
                            <Button asChild variant="ghost" size="sm">
                                <Link href={step.href}>{step.actionLabel}</Link>
                            </Button>
                        )}
                    </li>
                ))}
            </ol>
        </Card>
    );
}
