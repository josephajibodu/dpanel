import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';

import {
    SettingsRow,
    SettingsRows,
    SettingsSection,
} from '@/components/settings-section';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { disable, enable, show } from '@/routes/two-factor';
import { type BreadcrumbItem } from '@/types';

interface TwoFactorProps {
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Two-factor authentication',
        href: show.url(),
    },
];

function StatusPill({ enabled }: { enabled: boolean }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium',
                enabled
                    ? 'border-green-600/30 bg-green-500/10 text-green-700 dark:text-green-400'
                    : 'text-muted-foreground',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    enabled ? 'bg-green-500' : 'bg-neutral-400',
                )}
            />
            {enabled ? 'On' : 'Off'}
        </span>
    );
}

export default function TwoFactor({
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: TwoFactorProps) {
    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSettingsSubNavItems()}
        >
            <Head title="Two-factor authentication" />

            <SettingsLayout
                title="Two-factor authentication"
                description={
                    twoFactorEnabled
                        ? 'When you sign in, you enter your password and then a six-digit code from the authenticator app on your phone.'
                        : 'Add a second step to signing in: after your password, you enter a six-digit code from an authenticator app on your phone. Someone who learns your password still can’t get in.'
                }
            >
                <SettingsSection flush>
                    <SettingsRows>
                        <SettingsRow
                            label="Authenticator app"
                            description={
                                twoFactorEnabled
                                    ? 'Turning it off makes your account easier to break into.'
                                    : 'Works with 1Password, Google Authenticator, Authy, and similar apps.'
                            }
                        >
                            <StatusPill enabled={twoFactorEnabled} />
                            {twoFactorEnabled ? (
                                <Form {...disable.form()}>
                                    {({ processing }) => (
                                        <Button
                                            variant="outline"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Turn off
                                        </Button>
                                    )}
                                </Form>
                            ) : hasSetupData ? (
                                <Button onClick={() => setShowSetupModal(true)}>
                                    Continue setup
                                </Button>
                            ) : (
                                <Form
                                    {...enable.form()}
                                    onSuccess={() => setShowSetupModal(true)}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Turn on
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </SettingsRow>
                    </SettingsRows>
                </SettingsSection>

                {twoFactorEnabled && (
                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                    />
                )}

                <TwoFactorSetupModal
                    isOpen={showSetupModal}
                    onClose={() => setShowSetupModal(false)}
                    requiresConfirmation={requiresConfirmation}
                    twoFactorEnabled={twoFactorEnabled}
                    qrCodeSvg={qrCodeSvg}
                    manualSetupKey={manualSetupKey}
                    clearSetupData={clearSetupData}
                    fetchSetupData={fetchSetupData}
                    errors={errors}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
