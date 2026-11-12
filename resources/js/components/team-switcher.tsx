import { router, useForm, usePage } from '@inertiajs/react';
import {
    CaretUpDownIcon,
    CheckIcon,
    CircleNotchIcon,
    PlusIcon,
} from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type SharedData, type Team } from '@/types';

function teamInitials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

export function TeamSwitcher() {
    const { currentTeam, teams, auth } = usePage<SharedData>().props;
    const [createOpen, setCreateOpen] = useState(false);

    if (!currentTeam) {
        return null;
    }

    const switchTeam = (team: Pick<Team, 'id' | 'name' | 'personal_team'>) => {
        if (team.id === currentTeam.id) {
            return;
        }
        router.put('/user/current-team', { team_id: team.id });
    };

    return (
        <>
            <SidebarMenu className="flex-1">
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <SidebarMenuButton className="h-9 gap-2 px-1.5 text-sidebar-accent-foreground group-data-[collapsible=icon]:p-1! data-[state=open]:bg-sidebar-accent">
                                <div className="flex size-6 shrink-0 items-center justify-center rounded-md bg-brand text-white">
                                    <AppLogoIcon className="size-3.5 fill-current" />
                                </div>
                                <div className="grid flex-1 text-left leading-tight transition-opacity duration-200 ease-linear group-data-[collapsible=icon]:pointer-events-none group-data-[collapsible=icon]:opacity-0">
                                    <span className="truncate text-sm font-semibold">
                                        {currentTeam.name}
                                    </span>
                                </div>
                                <CaretUpDownIcon className="ml-auto size-3.5! shrink-0 text-sidebar-foreground/60 transition-opacity duration-200 ease-linear group-data-[collapsible=icon]:pointer-events-none group-data-[collapsible=icon]:opacity-0" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                            align="start"
                            side="bottom"
                            sideOffset={4}
                        >
                            <DropdownMenuLabel>Teams</DropdownMenuLabel>
                            {teams.map((team) => (
                                <DropdownMenuItem
                                    key={team.id}
                                    onClick={() => switchTeam(team)}
                                    className="cursor-pointer gap-2 p-2"
                                >
                                    <div className="flex size-6 items-center justify-center rounded-md bg-neutral-800 text-[10px] font-semibold text-white dark:bg-neutral-600">
                                        {teamInitials(team.name)}
                                    </div>
                                    {team.name}
                                    {team.id === currentTeam.id && (
                                        <CheckIcon className="ml-auto size-4" />
                                    )}
                                </DropdownMenuItem>
                            ))}
                            {/* Without the plan for it, the team settings page says so instead. */}
                            {!auth.teamLimit && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        className="cursor-pointer gap-2 p-2"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <div className="flex size-6 items-center justify-center rounded-md border bg-background">
                                            <PlusIcon className="size-4" />
                                        </div>
                                        Create team
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>

            <CreateTeamDialog open={createOpen} onOpenChange={setCreateOpen} />
        </>
    );
}

function CreateTeamDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        slug: '',
    });

    const [slugPreview, setSlugPreview] = useState('');
    const [slugLoading, setSlugLoading] = useState(false);
    const [showSlugInput, setShowSlugInput] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Reset state when dialog closes
    useEffect(() => {
        if (!open) {
            reset();
            setSlugPreview('');
            setSlugLoading(false);
            setShowSlugInput(false);
        }
    }, [open, reset]);

    // Debounce slug suggestion fetch as name changes
    useEffect(() => {
        if (showSlugInput) {
            return;
        }

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        if (!data.name.trim()) {
            setSlugPreview('');
            setSlugLoading(false);
            return;
        }

        setSlugLoading(true);

        debounceRef.current = setTimeout(async () => {
            try {
                const res = await fetch(
                    `/teams/slug-suggestion?name=${encodeURIComponent(data.name)}`,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );
                const json = (await res.json()) as { slug: string };
                setSlugPreview(json.slug ?? '');
                // Keep the form's slug in sync with the preview so it's
                // submitted when the user doesn't customise it.
                setData('slug', json.slug ?? '');
            } catch {
                setSlugPreview('');
            } finally {
                setSlugLoading(false);
            }
        }, 400);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, [data.name, showSlugInput, setData]);

    const handleShowSlugInput = () => {
        setShowSlugInput(true);
        // Pre-fill the custom slug input with the current preview
        setData('slug', slugPreview);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/teams', {
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New team</DialogTitle>
                    <DialogDescription>
                        Create a new team to collaborate with others and share
                        resources.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Team name */}
                    <div className="grid gap-2">
                        <Label htmlFor="team-name">Team name</Label>
                        <Input
                            id="team-name"
                            name="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Acme Corp"
                            autoFocus
                            required
                        />
                        <InputError message={errors.name} />

                        {/* Slug preview — hidden once user opens the slug input */}
                        {!showSlugInput && data.name.trim() && (
                            <p className="text-sm text-muted-foreground">
                                {slugLoading ? (
                                    <span className="inline-flex items-center gap-1">
                                        <CircleNotchIcon className="size-3 animate-spin" />
                                        Generating handle…
                                    </span>
                                ) : slugPreview ? (
                                    <>
                                        Your handle will be{' '}
                                        <span className="font-medium text-foreground">
                                            {slugPreview}
                                        </span>
                                        .{' '}
                                        <button
                                            type="button"
                                            className="font-semibold text-foreground underline-offset-2 hover:underline"
                                            onClick={handleShowSlugInput}
                                        >
                                            Change
                                        </button>
                                    </>
                                ) : null}
                            </p>
                        )}
                    </div>

                    {/* Custom slug input — shown after clicking "Change" */}
                    {showSlugInput && (
                        <div className="grid gap-2">
                            <Label htmlFor="team-slug">Team handle</Label>
                            <p className="text-sm text-muted-foreground">
                                Must only contain lowercase letters, numbers,
                                dots, and dashes.
                            </p>
                            <Input
                                id="team-slug"
                                name="slug"
                                value={data.slug}
                                onChange={(e) =>
                                    setData('slug', e.target.value)
                                }
                                placeholder="acme-corp"
                                autoFocus
                            />
                            <InputError message={errors.slug} />
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || !data.name.trim()}
                        >
                            {processing ? 'Creating…' : 'Create team'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
