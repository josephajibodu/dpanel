import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CircleNotchIcon,
    EnvelopeSimpleIcon,
    UserMinusIcon,
    XIcon,
} from '@phosphor-icons/react';
import { formatDistanceToNow } from 'date-fns';
import { useEffect, useRef, useState } from 'react';

import { destroy as destroyTeam } from '@/actions/App/Http/Controllers/TeamController';
import {
    destroy as cancelInvitation,
    store as storeInvitation,
} from '@/actions/App/Http/Controllers/TeamInvitationController';
import {
    leave as leaveTeam,
    destroy as removeMember,
    update as updateMemberRole,
} from '@/actions/App/Http/Controllers/TeamMemberController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import PlanLockedButton from '@/components/plan-locked-button';
import PlanUpgradeNotice from '@/components/plan-upgrade-notice';
import {
    SavedNote,
    SettingsRow,
    SettingsRows,
    SettingsSection,
} from '@/components/settings-section';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    type BreadcrumbItem,
    type SharedData,
    type Team,
    type TeamInvitation,
    type TeamMember,
    type TeamRoleKey,
} from '@/types';

type ManagedMember = TeamMember & {
    can_change_role: boolean;
    can_remove: boolean;
};

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

/** What each built-in role can do, in one line, so people can pick the right one. */
const roleDescriptions: Record<TeamRoleKey, string> = {
    owner: 'Full control, including deleting the team.',
    admin: 'Manages servers, sites, connections, and members.',
    developer: 'Deploys and manages sites, but not servers or members.',
    viewer: 'Can look around, but can’t change anything.',
};

export default function TeamSettings({
    team,
    members,
    invitations,
    assignableRoles,
    can,
}: Props) {
    const getInitials = useInitials();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Team settings', href: '/settings/team' },
    ];

    const summary = [
        `${members.length} ${members.length === 1 ? 'member' : 'members'}`,
        invitations.length > 0 &&
            `${invitations.length} pending ${invitations.length === 1 ? 'invite' : 'invites'}`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSettingsSubNavItems()}
        >
            <Head title="Team settings" />

            <SettingsLayout
                title={team.name}
                description={summary}
                avatar={getInitials(team.name)}
            >
                {can.updateTeam && <TeamNameForm team={team} />}

                <MembersCard
                    team={team}
                    members={members}
                    invitations={invitations}
                    assignableRoles={assignableRoles}
                    canInvite={can.inviteMembers}
                />

                {(can.leaveTeam || can.deleteTeam) && (
                    <SettingsSection
                        title="Danger zone"
                        description="Actions here take away access or remove data."
                        variant="danger"
                        flush
                    >
                        <SettingsRows>
                            {can.leaveTeam && <LeaveTeam team={team} />}
                            {can.deleteTeam && <DeleteTeam team={team} />}
                        </SettingsRows>
                    </SettingsSection>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}

function MembersCard({
    team,
    members,
    invitations,
    assignableRoles,
    canInvite,
}: {
    team: Team;
    members: ManagedMember[];
    invitations: ManagedInvitation[];
    assignableRoles: AssignableRole[];
    canInvite: boolean;
}) {
    const { auth, plan } = usePage<SharedData>().props;
    const membersLock = plan?.notIncluded.team_members ?? null;
    const getInitials = useInitials();
    const [memberToRemove, setMemberToRemove] = useState<ManagedMember | null>(
        null,
    );
    const [removing, setRemoving] = useState(false);

    const changeRole = (userId: number, role: TeamRoleKey) => {
        router.patch(
            updateMemberRole.url({ team: team.slug, user: userId }),
            { role },
            { preserveScroll: true },
        );
    };

    const hasActions =
        members.some((member) => member.can_remove) ||
        invitations.some((invitation) => invitation.can_cancel);

    const remove = () => {
        if (!memberToRemove) {
            return;
        }

        setRemoving(true);
        router.delete(
            removeMember.url({ team: team.slug, user: memberToRemove.id }),
            {
                preserveScroll: true,
                onFinish: () => {
                    setRemoving(false);
                    setMemberToRemove(null);
                },
            },
        );
    };

    return (
        <SettingsSection
            title="Members"
            description="People who can access this team’s servers and sites."
            headerAction={
                canInvite &&
                (membersLock ? (
                    <PlanLockedButton lock={membersLock} variant="outline">
                        Invite member
                    </PlanLockedButton>
                ) : (
                    <InviteMemberDialog
                        team={team}
                        assignableRoles={assignableRoles}
                    />
                ))
            }
            bare
        >
            {canInvite && membersLock && (
                <PlanUpgradeNotice message={membersLock.message} />
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Member</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Role</TableHead>
                        {hasActions && (
                            <TableHead className="w-12">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {members.map((member) => (
                        <TableRow key={member.id}>
                            <TableCell>
                                <div className="flex items-center gap-3">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-xs font-medium dark:bg-neutral-700">
                                        {getInitials(member.name)}
                                    </span>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {member.name}
                                            {member.id === auth.user.id && (
                                                <span className="ml-1.5 font-normal text-muted-foreground">
                                                    (you)
                                                </span>
                                            )}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <StatusBadge status="Active" color="green" />
                            </TableCell>
                            <TableCell>
                                {member.can_change_role ? (
                                    <Select
                                        value={member.role}
                                        onValueChange={(role) =>
                                            changeRole(
                                                member.id,
                                                role as TeamRoleKey,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            className="h-8 w-32"
                                            aria-label={`Role for ${member.name}`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {assignableRoles.map((role) => (
                                                <SelectItem
                                                    key={role.key}
                                                    value={role.key}
                                                >
                                                    {role.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <span className="capitalize">
                                        {member.role}
                                    </span>
                                )}
                            </TableCell>
                            {hasActions && (
                                <TableCell>
                                    {member.can_remove && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setMemberToRemove(member)
                                            }
                                            className="size-8 p-0 text-muted-foreground hover:text-destructive"
                                        >
                                            <UserMinusIcon className="size-4" />
                                            <span className="sr-only">
                                                Remove {member.name}
                                            </span>
                                        </Button>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}

                    {invitations.map((invitation) => (
                        <TableRow key={`invite-${invitation.id}`}>
                            <TableCell>
                                <div className="flex items-center gap-3">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full border border-dashed text-muted-foreground">
                                        <EnvelopeSimpleIcon className="size-3.5" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {invitation.email}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Invited{' '}
                                            {formatDistanceToNow(
                                                new Date(invitation.created_at),
                                                { addSuffix: true },
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <StatusBadge status="Pending" color="yellow" />
                            </TableCell>
                            <TableCell className="capitalize">
                                {invitation.role}
                            </TableCell>
                            {hasActions && (
                                <TableCell>
                                    {invitation.can_cancel && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    cancelInvitation.url({
                                                        team: team.slug,
                                                        invitation:
                                                            invitation.id,
                                                    }),
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                            className="size-8 p-0 text-muted-foreground"
                                        >
                                            <XIcon className="size-4" />
                                            <span className="sr-only">
                                                Cancel invitation for{' '}
                                                {invitation.email}
                                            </span>
                                        </Button>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <ConfirmDialog
                open={memberToRemove !== null}
                onOpenChange={(open) => !open && setMemberToRemove(null)}
                title={`Remove ${memberToRemove?.name ?? ''}?`}
                description="They lose access to this team’s servers and sites straight away, and their SSH keys are taken off them. To come back, someone has to invite them again."
                confirmLabel="Remove member"
                variant="destructive"
                loading={removing}
                onConfirm={remove}
            />
        </SettingsSection>
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
        <SettingsRow
            label="Leave team"
            description="You lose access until someone invites you again."
        >
            <Button variant="outline" onClick={() => setOpen(true)}>
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
        </SettingsRow>
    );
}

function DeleteTeam({ team }: { team: Team }) {
    const {
        data,
        setData,
        delete: destroy,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm({ name: '' });

    return (
        <SettingsRow
            label="Delete team"
            description="Removes the team and everything in it from FlitOps. This can’t be undone."
        >
            <Dialog
                onOpenChange={(open) => {
                    if (!open) {
                        reset();
                        clearErrors();
                    }
                }}
            >
                <DialogTrigger asChild>
                    <Button variant="destructive">Delete team</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {team.name}?</DialogTitle>
                        <DialogDescription>
                            This permanently removes the team from FlitOps: its
                            servers, sites, databases, workers, cron jobs,
                            deployment and backup history, the cloud provider
                            and storage accounts connected to it, and all
                            members and pending invitations.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2 text-sm text-muted-foreground">
                        <p>It does not touch anything outside FlitOps:</p>
                        <ul className="list-disc space-y-1 pl-5">
                            <li>
                                The servers themselves keep running at your
                                hosting provider, and keep being billed, until
                                you delete them there.
                            </li>
                            <li>
                                Backup files already uploaded stay in your
                                storage.
                            </li>
                            <li>
                                SSH keys already added to the servers stay on
                                them.
                            </li>
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
                                Type{' '}
                                <span className="font-semibold text-foreground">
                                    {team.name}
                                </span>{' '}
                                to confirm
                            </Label>
                            <Input
                                id="delete-team-name"
                                name="name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
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
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing || data.name !== team.name}
                            >
                                Delete team
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </SettingsRow>
    );
}

function TeamNameForm({ team }: { team: Team }) {
    const [showSlugInput, setShowSlugInput] = useState(false);
    const [slugPreview, setSlugPreview] = useState(team.slug);
    const [slugLoading, setSlugLoading] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const { data, setData, put, processing, errors, recentlySuccessful } =
        useForm({
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
    }, [data.name, showSlugInput, setData, team.slug]);

    const handleShowSlugInput = () => {
        setShowSlugInput(true);
        setData('slug', slugPreview);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/teams/${team.slug}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={handleSubmit}>
            <SettingsSection
                title="General"
                description={
                    <>
                        The team’s name, and the handle that appears in its web
                        addresses, like /{team.slug}/dashboard.
                    </>
                }
                footerHint="Changing the handle changes your team’s web addresses."
                footerAction={
                    <>
                        <SavedNote show={recentlySuccessful} />
                        <Button disabled={processing}>Save</Button>
                    </>
                }
            >
                <div className="space-y-4">
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
                            <p className="text-sm text-muted-foreground">
                                {slugLoading ? (
                                    <span className="inline-flex items-center gap-1">
                                        <CircleNotchIcon className="size-3 animate-spin" />
                                        Generating handle…
                                    </span>
                                ) : (
                                    <>
                                        Your handle is{' '}
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
                                )}
                            </p>
                        )}
                    </div>

                    {showSlugInput && (
                        <div className="grid gap-2">
                            <Label htmlFor="slug">Team handle</Label>
                            <p className="text-sm text-muted-foreground">
                                Must only contain lowercase letters, numbers,
                                dots, and dashes.
                            </p>
                            <Input
                                id="slug"
                                name="slug"
                                value={data.slug}
                                onChange={(e) =>
                                    setData('slug', e.target.value)
                                }
                                placeholder="my-team"
                                autoFocus
                            />
                            <InputError message={errors.slug} />
                        </div>
                    )}
                </div>
            </SettingsSection>
        </form>
    );
}

function InviteMemberDialog({
    team,
    assignableRoles,
}: {
    team: Team;
    assignableRoles: AssignableRole[];
}) {
    const [open, setOpen] = useState(false);
    const defaultRole = assignableRoles.some((role) => role.key === 'developer')
        ? 'developer'
        : (assignableRoles[0]?.key ?? '');

    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm<{ email: string; role: string }>({
            email: '',
            role: defaultRole,
        });

    return (
        <Dialog
            open={open}
            onOpenChange={(isOpen) => {
                setOpen(isOpen);
                if (!isOpen) {
                    reset();
                    clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">Invite member</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Invite member</DialogTitle>
                    <DialogDescription>
                        We’ll email them a link to join {team.name}. The link
                        works until they accept it or you cancel it.
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(storeInvitation.url(team.slug), {
                            preserveScroll: true,
                            onSuccess: () => {
                                reset();
                                setOpen(false);
                            },
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
                            autoFocus
                            required
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <Select
                            value={data.role}
                            onValueChange={(role) => setData('role', role)}
                        >
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
                        {data.role && (
                            <p className="text-sm text-muted-foreground">
                                {roleDescriptions[data.role as TeamRoleKey]}
                            </p>
                        )}
                        <InputError message={errors.role} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            Send invitation
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
