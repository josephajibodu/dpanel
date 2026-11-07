import { Link } from '@inertiajs/react';

import LegalDocument, {
    type LegalSection,
} from '@/components/marketing/legal-document';
import MarketingLayout from '@/layouts/marketing-layout';
import { contact, privacy } from '@/routes';

const sections: LegalSection[] = [
    {
        id: 'agreement',
        title: 'Agreement to these terms',
        body: (
            <p>
                By creating an account or using flitops (the “Service”), you
                agree to these Terms of Service. If you use the Service on
                behalf of an organisation, you agree on its behalf and confirm
                you have authority to do so.
            </p>
        ),
    },
    {
        id: 'service',
        title: 'What the Service does',
        body: (
            <>
                <p>
                    flitops is a control panel that provisions and configures
                    servers in cloud accounts you own, and deploys and operates
                    applications on them. Servers are created and billed by your
                    cloud provider, not by us.
                </p>
                <p>
                    You remain responsible for your cloud accounts, the servers
                    in them, and any charges your provider makes for them.
                </p>
            </>
        ),
    },
    {
        id: 'accounts',
        title: 'Your account',
        body: (
            <ul>
                <li>
                    You must provide accurate information and keep your login
                    credentials secure.
                </li>
                <li>
                    We strongly recommend enabling two-factor authentication.
                </li>
                <li>
                    You are responsible for activity under your account and for
                    the members you invite to your teams.
                </li>
                <li>
                    Tell us promptly if you believe your account has been
                    compromised.
                </li>
            </ul>
        ),
    },
    {
        id: 'access',
        title: 'Access to your infrastructure',
        body: (
            <p>
                To provide the Service you grant flitops permission to use the
                cloud provider tokens, source control connections, storage
                credentials, and SSH access you supply in order to create,
                configure, deploy to, and manage your servers on your
                instructions. We use this access only to operate the Service for
                you.
            </p>
        ),
    },
    {
        id: 'acceptable-use',
        title: 'Acceptable use',
        body: (
            <>
                <p>You agree not to use the Service to:</p>
                <ul>
                    <li>break the law or infringe the rights of others;</li>
                    <li>
                        distribute malware, send spam, or run phishing or fraud;
                    </li>
                    <li>
                        attack, scan, or disrupt systems you do not own or have
                        permission to test;
                    </li>
                    <li>
                        attempt to access other customers’ data or interfere
                        with the Service itself.
                    </li>
                </ul>
                <p>
                    You must also comply with the acceptable use policies of
                    your cloud and source control providers.
                </p>
            </>
        ),
    },
    {
        id: 'billing',
        title: 'Plans and billing',
        body: (
            <>
                <p>
                    Paid plans are billed in advance, monthly or yearly, and
                    renew automatically until cancelled. You can cancel at any
                    time; access continues until the end of the period you have
                    paid for.
                </p>
                <p>
                    Fees are exclusive of taxes unless stated. We may change
                    prices with at least 30 days’ notice, and changes apply from
                    your next renewal.
                </p>
            </>
        ),
    },
    {
        id: 'your-content',
        title: 'Your content',
        body: (
            <p>
                Your code, configuration, and data remain yours. You grant us
                only the limited rights needed to store, process, and transmit
                them to operate the Service. Our handling of personal data is
                described in our <Link href={privacy()}>Privacy Policy</Link>.
            </p>
        ),
    },
    {
        id: 'availability',
        title: 'Availability and changes',
        body: (
            <p>
                We work hard to keep the Service reliable, but it is provided
                “as is” and “as available”. Your servers run independently of
                flitops, so an interruption to the Service does not take your
                sites offline. We may improve or change features over time and
                will give reasonable notice of material changes.
            </p>
        ),
    },
    {
        id: 'liability',
        title: 'Limitation of liability',
        body: (
            <p>
                To the extent permitted by law, flitops is not liable for
                indirect, incidental, or consequential damages, or for lost
                profits or data. Our total liability for any claim is limited to
                the amount you paid us in the twelve months before the claim
                arose.
            </p>
        ),
    },
    {
        id: 'termination',
        title: 'Termination',
        body: (
            <p>
                You may close your account at any time. We may suspend or end
                access if you materially breach these terms. When an account
                closes, your servers keep running in your cloud account; we
                delete the credentials and configuration we hold for you within
                a reasonable period.
            </p>
        ),
    },
    {
        id: 'changes',
        title: 'Changes to these terms',
        body: (
            <p>
                We may update these terms from time to time. If a change is
                material we will notify you by email or in the dashboard before
                it takes effect. Continuing to use the Service after that means
                you accept the updated terms.
            </p>
        ),
    },
    {
        id: 'contact',
        title: 'Contact',
        body: (
            <p>
                Questions about these terms?{' '}
                <Link href={contact()}>Get in touch</Link> or email{' '}
                <a href="mailto:hello@flitops.com">hello@flitops.com</a>.
            </p>
        ),
    },
];

export default function Terms() {
    return (
        <MarketingLayout
            title="Terms of Service"
            description="The terms that apply when you use flitops."
        >
            <LegalDocument
                title="Terms of Service"
                effectiveDate="November 7, 2026"
                summary="The short version: your servers and code are yours, you are responsible for how you use them, and we use the access you give us only to run the service for you."
                sections={sections}
            />
        </MarketingLayout>
    );
}
