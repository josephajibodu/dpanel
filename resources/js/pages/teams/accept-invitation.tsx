import { Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { login, register } from '@/routes';
import { type TeamInvitation } from '@/types';

interface TeamWithName {
    id: number;
    name: string;
}

interface Props {
    invitation: TeamInvitation & { team: TeamWithName };
    token: string;
}

export default function AcceptInvitation({ invitation, token }: Props) {
    return (
        <AuthLayout
            title="Team invitation"
            description={`You've been invited to join ${invitation.team.name}`}
        >
            <Head title="Accept team invitation" />

            <div className="space-y-6 text-center">
                <div className="bg-muted mx-auto flex size-16 items-center justify-center rounded-full">
                    <Users className="text-muted-foreground size-8" />
                </div>

                <div className="space-y-1">
                    <p className="text-sm">
                        You have been invited to join{' '}
                        <span className="font-semibold">{invitation.team.name}</span>{' '}
                        as a{' '}
                        <span className="font-semibold capitalize">{invitation.role}</span>.
                    </p>
                    <p className="text-muted-foreground text-sm">
                        Sign in or create an account to accept this invitation.
                    </p>
                </div>

                <div className="flex flex-col gap-3">
                    <Button asChild>
                        <Link href={`${login().url}?redirect=/invitations/${token}/confirm`}>
                            Sign in to accept
                        </Link>
                    </Button>

                    <Button variant="outline" asChild>
                        <Link href={`${register().url}?redirect=/invitations/${token}/confirm`}>
                            Create an account
                        </Link>
                    </Button>
                </div>

                <p className="text-muted-foreground text-xs">
                    The invitation was sent to{' '}
                    <span className="font-medium">{invitation.email}</span>.
                    You must sign in with that email address to accept it.
                </p>
            </div>
        </AuthLayout>
    );
}
