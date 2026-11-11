import { Form } from '@inertiajs/react';
import {
    ArrowsClockwiseIcon,
    EyeIcon,
    EyeSlashIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

import AlertError from './alert-error';

interface TwoFactorRecoveryCodesProps {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
}

export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: TwoFactorRecoveryCodesProps) {
    const [codesAreVisible, setCodesAreVisible] = useState<boolean>(false);
    const codesSectionRef = useRef<HTMLDivElement | null>(null);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;

    const toggleCodesVisibility = useCallback(async () => {
        if (!codesAreVisible && !recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setCodesAreVisible(!codesAreVisible);

        if (!codesAreVisible) {
            setTimeout(() => {
                codesSectionRef.current?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                });
            });
        }
    }, [codesAreVisible, recoveryCodesList.length, fetchRecoveryCodes]);

    useEffect(() => {
        if (!recoveryCodesList.length) {
            fetchRecoveryCodes();
        }
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    const RecoveryCodeIconComponent = codesAreVisible ? EyeSlashIcon : EyeIcon;

    return (
        <SettingsSection
            title="Recovery codes"
            description="One-time codes that get you in if you lose your phone. Keep them somewhere safe, like your password manager."
            footerHint="Each code works once. Regenerating makes the old codes stop working."
            footerAction={
                <>
                    {canRegenerateCodes && (
                        <Form
                            {...regenerateRecoveryCodes.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={fetchRecoveryCodes}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="ghost"
                                    type="submit"
                                    disabled={processing}
                                    aria-describedby="regenerate-warning"
                                >
                                    <ArrowsClockwiseIcon /> Regenerate
                                </Button>
                            )}
                        </Form>
                    )}
                    <Button
                        variant="outline"
                        onClick={toggleCodesVisibility}
                        aria-expanded={codesAreVisible}
                        aria-controls="recovery-codes-section"
                    >
                        <RecoveryCodeIconComponent
                            className="size-4"
                            aria-hidden="true"
                        />
                        {codesAreVisible ? 'Hide codes' : 'Show codes'}
                    </Button>
                </>
            }
        >
            {codesAreVisible && (
                <div id="recovery-codes-section">
                    <div className="space-y-3">
                        {errors?.length ? (
                            <AlertError errors={errors} />
                        ) : (
                            <>
                                <div
                                    ref={codesSectionRef}
                                    className="grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg bg-muted p-4 font-mono text-sm"
                                    role="list"
                                    aria-label="Recovery codes"
                                >
                                    {recoveryCodesList.length ? (
                                        recoveryCodesList.map((code, index) => (
                                            <div
                                                key={index}
                                                role="listitem"
                                                className="select-text"
                                            >
                                                {code}
                                            </div>
                                        ))
                                    ) : (
                                        <div
                                            className="space-y-2"
                                            aria-label="Loading recovery codes"
                                        >
                                            {Array.from(
                                                { length: 8 },
                                                (_, index) => (
                                                    <div
                                                        key={index}
                                                        className="h-4 animate-pulse rounded bg-muted-foreground/20"
                                                        aria-hidden="true"
                                                    />
                                                ),
                                            )}
                                        </div>
                                    )}
                                </div>

                                <p id="regenerate-warning" className="sr-only">
                                    Regenerating replaces all of your recovery
                                    codes.
                                </p>
                            </>
                        )}
                    </div>
                </div>
            )}
        </SettingsSection>
    );
}
