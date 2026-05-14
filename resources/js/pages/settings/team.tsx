import { Form, Head, router, usePage } from '@inertiajs/react';
import { Trash2, UserMinus, X } from 'lucide-react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData, type Team, type TeamInvitation, type TeamMember } from '@/types';

interface Props {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
}

export default function TeamSettings({ team, members, invitations }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [inviteEmail, setInviteEmail] = useState('');

    const isOwner = auth.user.id === team.user_id;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Team settings', href: `/teams/${team.id}/settings` },
    ];

    const removeMember = (userId: number) => {
        router.delete(`/teams/${team.id}/members/${userId}`, {
            preserveScroll: true,
        });
    };

    const cancelInvitation = (invitationId: number) => {
        router.delete(`/teams/${team.id}/invitations/${invitationId}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} subNavItems={getSettingsSubNavItems(team.id)}>
            <Head title="Team settings" />

            <h1 className="sr-only">Team Settings</h1>

            <SettingsLayout>
                {isOwner && (
                    <div className="space-y-6">
                        <HeadingSmall
                            title="Team name"
                            description="Update your team's display name"
                        />

                        <Form
                            action={`/teams/${team.id}`}
                            method="put"
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing, recentlySuccessful, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={team.name}
                                            required
                                            placeholder="Team name"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="flex items-center gap-4">
                                        <Button disabled={processing}>Save</Button>
                                        {recentlySuccessful && (
                                            <p className="text-sm text-neutral-600">Saved</p>
                                        )}
                                    </div>
                                </>
                            )}
                        </Form>
                    </div>
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
                                    <span className="text-muted-foreground rounded bg-neutral-100 px-2 py-0.5 text-xs capitalize dark:bg-neutral-800">
                                        {member.role}
                                    </span>
                                    {isOwner && member.id !== auth.user.id && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => removeMember(member.id)}
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

                {isOwner && (
                    <div className="space-y-6">
                        <HeadingSmall
                            title="Invite member"
                            description="Send an invitation to add someone to this team"
                        />

                        <Form
                            action={`/teams/${team.id}/invitations`}
                            method="post"
                            options={{ preserveScroll: true, onSuccess: () => setInviteEmail('') }}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email address</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            value={inviteEmail}
                                            onChange={(e) => setInviteEmail(e.target.value)}
                                            placeholder="colleague@example.com"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <input type="hidden" name="role" value="member" />

                                    <Button disabled={processing}>Send invitation</Button>
                                </>
                            )}
                        </Form>

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
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => cancelInvitation(inv.id)}
                                                className="size-8 p-0"
                                            >
                                                <X className="size-4" />
                                                <span className="sr-only">Cancel</span>
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}

                {isOwner && !team.personal_team && (
                    <div className="space-y-6">
                        <HeadingSmall
                            title="Delete team"
                            description="Permanently delete this team and all of its resources"
                        />

                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (confirm('Are you sure you want to delete this team? This action cannot be undone.')) {
                                    router.delete(`/teams/${team.id}`);
                                }
                            }}
                        >
                            <Trash2 className="mr-2 size-4" />
                            Delete team
                        </Button>
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
