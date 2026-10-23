import { Head, router, useForm } from '@inertiajs/react';
import { Loader2, LogOut, Trash2, UserMinus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { destroy as destroyTeam } from '@/actions/App/Http/Controllers/TeamController';
import { destroy as cancelInvitation, store as storeInvitation } from '@/actions/App/Http/Controllers/TeamInvitationController';
import { leave as leaveTeam, destroy as removeMember, update as updateMemberRole } from '@/actions/App/Http/Controllers/TeamMemberController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type Team, type TeamInvitation, type TeamMember, type TeamRoleKey } from '@/types';

type ManagedMember = TeamMember & { can_change_role: boolean; can_remove: boolean };

type ManagedInvitation = TeamInvitation & { can_cancel: boolean };

interface AssignableRole {
    key: TeamRoleKey;
    label: string;
}

interface Props {
    team: Team;
    members: ManagedMember[];
    invitations: ManagedInvitation[];
    assignableRoles: AssignableRole[];
    can: {
        updateTeam: boolean;
        deleteTeam: boolean;
        leaveTeam: boolean;
        inviteMembers: boolean;
    };
}

export default function TeamSettings({ team, members, invitations, assignableRoles, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Team settings', href: '/settings/team' },
    ];

    const changeRole = (userId: number, role: TeamRoleKey) => {
        router.patch(updateMemberRole.url({ team: team.slug, user: userId }), { role }, { preserveScroll: true });
    };

    const remove = (userId: number) => {
        router.delete(removeMember.url({ team: team.slug, user: userId }), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} subNavItems={getSettingsSubNavItems()}>
            <Head title="Team settings" />

            <h1 className="sr-only">Team Settings</h1>

            <SettingsLayout>
                {can.updateTeam && (
                    <TeamNameForm team={team} />
                )}

                <div className="space-y-6">
                    <HeadingSmall
                        title="Team members"
                        description="People who have access to this team"
                    />

                    <ul className="divide-y rounded-lg border">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex items-center justify-between px-4 py-3"
                            >
                                <div>
                                    <p className="text-sm font-medium">{member.name}</p>
                                    <p className="text-muted-foreground text-xs">{member.email}</p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {member.can_change_role ? (
                                        <Select
                                            value={member.role}
                                            onValueChange={(role) => changeRole(member.id, role as TeamRoleKey)}
                                        >
                                            <SelectTrigger className="h-8 w-32" aria-label={`Role for ${member.name}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {assignableRoles.map((role) => (
                                                    <SelectItem key={role.key} value={role.key}>
                                                        {role.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <span className="text-muted-foreground rounded bg-neutral-100 px-2 py-0.5 text-xs capitalize dark:bg-neutral-800">
                                            {member.role}
                                        </span>
                                    )}
                                    {member.can_remove && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => remove(member.id)}
                                            className="text-destructive hover:text-destructive size-8 p-0"
                                        >
                                            <UserMinus className="size-4" />
                                            <span className="sr-only">Remove</span>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                {can.inviteMembers && (
                    <InviteMemberForm team={team} invitations={invitations} assignableRoles={assignableRoles} />
                )}

                {can.leaveTeam && <LeaveTeam team={team} />}

                {can.deleteTeam && <DeleteTeam team={team} />}
            </SettingsLayout>
        </AppLayout>
    );
}

function LeaveTeam({ team }: { team: Team }) {
    const [open, setOpen] = useState(false);
    const [leaving, setLeaving] = useState(false);

    const leave = () => {
        setLeaving(true);
        router.delete(leaveTeam.url(team.slug), {
            onFinish: () => {
                setLeaving(false);
                setOpen(false);
            },
        });
    };

    return (
        <div className="space-y-6">
            <HeadingSmall
                title="Leave team"
                description="Stop being a member of this team"
            />

            <Button variant="outline" onClick={() => setOpen(true)}>
                <LogOut className="mr-2 size-4" />
                Leave team
            </Button>

            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title={`Leave ${team.name}?`}
                description="You will lose access to this team's servers and sites, and any of your SSH keys on them will be removed. To come back, someone in the team has to invite you again."
                confirmLabel="Leave team"
                variant="destructive"
                loading={leaving}
                onConfirm={leave}
            />
        </div>
    );
}

function DeleteTeam({ team }: { team: Team }) {
    const { data, setData, delete: destroy, processing, errors, reset, clearErrors } = useForm({ name: '' });

    return (
        <div className="space-y-6">
            <HeadingSmall
                title="Delete team"
                description="Permanently delete this team and everything it holds in FleetOps"
            />

            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">Please proceed with caution, this cannot be undone.</p>
                </div>

                <Dialog
                    onOpenChange={(open) => {
                        if (!open) {
                            reset();
                            clearErrors();
                        }
                    }}
                >
                    <DialogTrigger asChild>
                        <Button variant="destructive">
                            <Trash2 className="mr-2 size-4" />
                            Delete team
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete {team.name}?</DialogTitle>
                            <DialogDescription>
                                This permanently removes the team from FleetOps: its servers, sites, databases, workers, cron jobs,
                                deployment and backup history, the cloud provider and storage accounts connected to it, and all
                                members and pending invitations.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="text-muted-foreground space-y-2 text-sm">
                            <p>It does not touch anything outside FleetOps:</p>
                            <ul className="list-disc space-y-1 pl-5">
                                <li>
                                    The servers themselves keep running at your hosting provider, and keep being billed, until you
                                    delete them there.
                                </li>
                                <li>Backup files already uploaded stay in your storage.</li>
                                <li>SSH keys already added to the servers stay on them.</li>
                            </ul>
                        </div>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                destroy(destroyTeam.url(team.slug));
                            }}
                            className="space-y-6"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="delete-team-name">
                                    Type <span className="text-foreground font-semibold">{team.name}</span> to confirm
                                </Label>
                                <Input
                                    id="delete-team-name"
                                    name="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    autoComplete="off"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" variant="destructive" disabled={processing || data.name !== team.name}>
                                    Delete team
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}

function TeamNameForm({ team }: { team: Team }) {
    const [showSlugInput, setShowSlugInput] = useState(false);
    const [slugPreview, setSlugPreview] = useState(team.slug);
    const [slugLoading, setSlugLoading] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const { data, setData, put, processing, errors } = useForm({
        name: team.name,
        slug: team.slug,
    });

    // Debounce slug suggestion fetch as name changes
    useEffect(() => {
        if (showSlugInput) {
            return;
        }

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        if (!data.name.trim()) {
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
                setSlugPreview(json.slug ?? team.slug);
                setData('slug', json.slug ?? team.slug);
            } catch {
                setSlugPreview(team.slug);
            } finally {
                setSlugLoading(false);
            }
        }, 400);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, [data.name, showSlugInput]);

    const handleShowSlugInput = () => {
        setShowSlugInput(true);
        setData('slug', slugPreview);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/teams/${team.slug}`, { preserveScroll: true });
    };

    return (
        <div className="space-y-6">
            <HeadingSmall
                title="Team name"
                description="Update your team's display name"
            />

            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        placeholder="Team name"
                    />
                    <InputError message={errors.name} />

                    {!showSlugInput && (
                        <p className="text-muted-foreground text-sm">
                            {slugLoading ? (
                                <span className="inline-flex items-center gap-1">
                                    <Loader2 className="size-3 animate-spin" />
                                    Generating handle…
                                </span>
                            ) : (
                                <>
                                    Your handle is{' '}
                                    <span className="text-foreground font-medium">{slugPreview}</span>.{' '}
                                    <button
                                        type="button"
                                        className="text-foreground font-semibold underline-offset-2 hover:underline"
                                        onClick={handleShowSlugInput}
                                    >
                                        Change
                                    </button>
                                </>
                            )}
                        </p>
                    )}
                </div>

                {showSlugInput && (
                    <div className="grid gap-2">
                        <Label htmlFor="slug">Team handle</Label>
                        <p className="text-muted-foreground text-sm">
                            Must only contain lowercase letters, numbers, dots, and dashes.
                        </p>
                        <Input
                            id="slug"
                            name="slug"
                            value={data.slug}
                            onChange={(e) => setData('slug', e.target.value)}
                            placeholder="my-team"
                            autoFocus
                        />
                        <InputError message={errors.slug} />
                    </div>
                )}

                <Button disabled={processing}>Save</Button>
            </form>
        </div>
    );
}

function InviteMemberForm({
    team,
    invitations,
    assignableRoles,
}: {
    team: Team;
    invitations: ManagedInvitation[];
    assignableRoles: AssignableRole[];
}) {
    const defaultRole = assignableRoles.some((role) => role.key === 'developer') ? 'developer' : (assignableRoles[0]?.key ?? '');

    const { data, setData, post, processing, errors, reset } = useForm<{ email: string; role: string }>({
        email: '',
        role: defaultRole,
    });

    return (
        <div className="space-y-6">
            <HeadingSmall
                title="Invite member"
                description="Send an invitation to add someone to this team"
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(storeInvitation.url(team.slug), {
                        preserveScroll: true,
                        onSuccess: () => reset('email'),
                    });
                }}
                className="space-y-4"
            >
                <div className="grid gap-2">
                    <Label htmlFor="email">Email address</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="colleague@example.com"
                        required
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="role">Role</Label>
                    <Select value={data.role} onValueChange={(role) => setData('role', role)}>
                        <SelectTrigger id="role">
                            <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                        <SelectContent>
                            {assignableRoles.map((role) => (
                                <SelectItem key={role.key} value={role.key}>
                                    {role.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.role} />
                </div>

                <Button type="submit" disabled={processing}>Send invitation</Button>
            </form>

            {invitations.length > 0 && (
                <div className="space-y-3">
                    <p className="text-muted-foreground text-sm font-medium">
                        Pending invitations
                    </p>
                    <ul className="divide-y rounded-lg border">
                        {invitations.map((inv) => (
                            <li
                                key={inv.id}
                                className="flex items-center justify-between px-4 py-3"
                            >
                                <div>
                                    <p className="text-sm">{inv.email}</p>
                                    <p className="text-muted-foreground text-xs capitalize">
                                        {inv.role} · Pending
                                    </p>
                                </div>
                                {inv.can_cancel && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(cancelInvitation.url({ team: team.slug, invitation: inv.id }), {
                                                preserveScroll: true,
                                            })
                                        }
                                        className="size-8 p-0"
                                    >
                                        <X className="size-4" />
                                        <span className="sr-only">Cancel</span>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
