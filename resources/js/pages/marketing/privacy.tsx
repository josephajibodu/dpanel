import { Link } from '@inertiajs/react';

import LegalDocument, {
    type LegalSection,
} from '@/components/marketing/legal-document';
import MarketingLayout from '@/layouts/marketing-layout';
import { contact, security } from '@/routes';

const sections: LegalSection[] = [
    {
        id: 'overview',
        title: 'Overview',
        body: (
            <p>
                This policy explains what information flitops collects, why, and
                the choices you have. We collect only what we need to run the
                Service, and we do not sell your personal information.
            </p>
        ),
    },
    {
        id: 'information',
        title: 'Information we collect',
        body: (
            <ul>
                <li>
                    <strong>Account information:</strong> your name, email
                    address, password (stored as a one-way hash), and two-factor
                    settings.
                </li>
                <li>
                    <strong>Team information:</strong> team names, members,
                    roles, and invitations you send.
                </li>
                <li>
                    <strong>Infrastructure credentials:</strong> cloud provider
                    tokens, source control connections, storage keys, and SSH
                    keys you add. These are encrypted at rest.
                </li>
                <li>
                    <strong>Configuration:</strong> server, site, domain,
                    environment, deploy script, worker, and backup settings,
                    plus deployment and command logs.
                </li>
                <li>
                    <strong>Operational data:</strong> server metrics, uptime
                    results, and technical logs such as IP addresses and browser
                    details when you use the dashboard.
                </li>
            </ul>
        ),
    },
    {
        id: 'not-collected',
        title: 'What we do not collect',
        body: (
            <p>
                Your application’s traffic and database contents go directly to
                your servers and do not pass through flitops. Database backups
                are written straight to the storage provider you choose.
            </p>
        ),
    },
    {
        id: 'use',
        title: 'How we use information',
        body: (
            <ul>
                <li>
                    to provision, configure, deploy to, and monitor your servers
                    on your instructions;
                </li>
                <li>
                    to send service emails such as invitations, alerts, and
                    account notices;
                </li>
                <li>
                    to keep the Service secure, prevent abuse, and fix problems;
                </li>
                <li>to provide support when you ask for it.</li>
            </ul>
        ),
    },
    {
        id: 'sharing',
        title: 'Who we share it with',
        body: (
            <>
                <p>
                    We share information only with the services needed to do
                    what you ask:
                </p>
                <ul>
                    <li>
                        the cloud, source control, and storage providers you
                        connect;
                    </li>
                    <li>
                        infrastructure providers that host and email on our
                        behalf, under confidentiality terms;
                    </li>
                    <li>authorities, when the law requires it.</li>
                </ul>
            </>
        ),
    },
    {
        id: 'security',
        title: 'How we protect it',
        body: (
            <p>
                Credentials and secrets are encrypted at rest and hidden from
                the dashboard unless you choose to reveal them. Read more on our{' '}
                <Link href={security()}>security page</Link>.
            </p>
        ),
    },
    {
        id: 'retention',
        title: 'Retention',
        body: (
            <p>
                We keep your information for as long as your account is active.
                When you delete your account or remove a resource, we delete the
                related credentials and configuration within a reasonable
                period, except where we must keep records to meet legal
                obligations.
            </p>
        ),
    },
    {
        id: 'rights',
        title: 'Your rights',
        body: (
            <p>
                Depending on where you live, you may have the right to access,
                correct, export, or delete your personal information, and to
                object to certain processing. You can update most information in
                your account settings, or contact us and we will help.
            </p>
        ),
    },
    {
        id: 'cookies',
        title: 'Cookies',
        body: (
            <p>
                We use only the cookies needed to keep you signed in, protect
                forms, and remember your theme. We do not use advertising
                cookies.
            </p>
        ),
    },
    {
        id: 'changes',
        title: 'Changes to this policy',
        body: (
            <p>
                If we make material changes we will let you know by email or in
                the dashboard before they take effect.
            </p>
        ),
    },
    {
        id: 'contact',
        title: 'Contact',
        body: (
            <p>
                Questions or requests about your data?{' '}
                <Link href={contact()}>Get in touch</Link> or email{' '}
                <a href="mailto:hello@flitops.com">hello@flitops.com</a>.
            </p>
        ),
    },
];

export default function Privacy() {
    return (
        <MarketingLayout
            title="Privacy Policy"
            description="How flitops collects, uses, and protects your information."
        >
            <LegalDocument
                title="Privacy Policy"
                effectiveDate="November 7, 2026"
                summary="We collect what we need to run your servers for you, encrypt the credentials you trust us with, and never sell your information."
                sections={sections}
            />
        </MarketingLayout>
    );
}
