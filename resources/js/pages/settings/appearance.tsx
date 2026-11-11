import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import { SettingsSection } from '@/components/settings-section';
import { getSettingsSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

export default function Appearance() {
    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSettingsSubNavItems()}
        >
            <Head title="Appearance settings" />

            <SettingsLayout title="Appearance">
                <SettingsSection
                    title="Theme"
                    description="Choose how FlitOps looks on this device. Changes apply straight away."
                >
                    <AppearanceTabs />
                </SettingsSection>
            </SettingsLayout>
        </AppLayout>
    );
}
