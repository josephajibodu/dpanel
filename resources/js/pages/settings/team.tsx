import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Trash2, UserMinus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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

    const isOwner = auth.user.id === team.user_id;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Team settings', href: '/settings/team' },
    ];

    const removeMember = (userId: number) => {
        router.delete(`/teams/${team.slug}/members/${userId}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} subNavItems={getSettingsSubNavItems()}>
            <Head title="Team settings" />

            <h1 className="sr-only">Team Settings</h1>

            <SettingsLayout>
                {isOwner && (
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
                    <InviteMemberForm team={team} invitations={invitations} />
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
                                    router.delete(`/teams/${team.slug}`);
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

function InviteMemberForm({ team, invitations }: { team: Team; invitations: TeamInvitation[] }) {
    const [inviteEmail, setInviteEmail] = useState('');

    return (
        <div className="space-y-6">
            <HeadingSmall
                title="Invite member"
                description="Send an invitation to add someone to this team"
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    router.post(
                        `/teams/${team.slug}/invitations`,
                        { email: inviteEmail, role: 'member' },
                        { preserveScroll: true, onSuccess: () => setInviteEmail('') },
                    );
                }}
                className="space-y-4"
            >
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
                </div>

                <Button type="submit">Send invitation</Button>
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
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(`/teams/${team.slug}/invitations/${inv.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
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
    );
}
