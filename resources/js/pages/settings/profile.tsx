import { Form, Head, Link, usePage } from '@inertiajs/react';

import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import InputError from '@/components/input-error';
import { SavedNote, SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();
    const isUnverified =
        mustVerifyEmail && auth.user.email_verified_at === null;

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSettingsSubNavItems()}
        >
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <SettingsLayout>
                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <SettingsSection
                                title="Profile"
                                description="Your name and email, as your teammates see them on invitations and member lists."
                                footerHint="Emails from FlitOps go to this address."
                                footerAction={
                                    <>
                                        <SavedNote show={recentlySuccessful} />
                                        <Button
                                            disabled={processing}
                                            data-test="update-profile-button"
                                        >
                                            Save
                                        </Button>
                                    </>
                                }
                            >
                                <div className="flex flex-col gap-6 sm:flex-row">
                                    <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-lg font-medium dark:bg-neutral-700">
                                        {getInitials(auth.user.name)}
                                    </span>

                                    <div className="grid flex-1 gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                defaultValue={auth.user.name}
                                                name="name"
                                                required
                                                autoComplete="name"
                                                placeholder="Ada Lovelace"
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="email">
                                                Email address
                                            </Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                defaultValue={auth.user.email}
                                                name="email"
                                                required
                                                autoComplete="username"
                                                placeholder="ada@example.com"
                                            />
                                            <InputError
                                                message={errors.email}
                                            />
                                        </div>

                                        {isUnverified && (
                                            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 sm:col-span-2 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                                                Your email address isn’t
                                                verified yet.{' '}
                                                <Link
                                                    href={send()}
                                                    as="button"
                                                    className="font-medium underline underline-offset-4"
                                                >
                                                    Resend the verification
                                                    email
                                                </Link>
                                                {status ===
                                                    'verification-link-sent' && (
                                                    <p className="mt-1 font-medium">
                                                        A new verification link
                                                        is on its way.
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </SettingsSection>

                            <SettingsSection
                                title="Git identity"
                                description="The author name and email on Git commits made from your sites, for example when Statamic saves content to the repository."
                                footerHint="Optional. Only needed if a site commits back to its repository."
                                footerAction={
                                    <Button disabled={processing}>Save</Button>
                                }
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="git_name">
                                            Git name
                                        </Label>
                                        <Input
                                            id="git_name"
                                            defaultValue={
                                                auth.user.git_name || ''
                                            }
                                            name="git_name"
                                            autoComplete="name"
                                            placeholder="Ada Lovelace"
                                        />
                                        <InputError message={errors.git_name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="git_email">
                                            Git email
                                        </Label>
                                        <Input
                                            id="git_email"
                                            type="email"
                                            defaultValue={
                                                auth.user.git_email || ''
                                            }
                                            name="git_email"
                                            autoComplete="email"
                                            placeholder="ada@example.com"
                                        />
                                        <InputError
                                            message={errors.git_email}
                                        />
                                    </div>
                                </div>
                            </SettingsSection>
                        </>
                    )}
                </Form>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
