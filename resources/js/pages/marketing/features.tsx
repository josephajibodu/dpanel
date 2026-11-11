import CallToAction from '@/components/marketing/call-to-action';
import PageHero from '@/components/marketing/page-hero';
import MarketingLayout from '@/layouts/marketing-layout';

const featureGroups = [
    {
        id: 'servers',
        title: 'Servers',
        summary:
            'Provision on your own cloud, or connect any machine you already run.',
        items: [
            {
                title: 'Five ways to get a server',
                description:
                    'Create servers on DigitalOcean, Hetzner, Vultr, or AWS from one form, or connect any Ubuntu server over SSH.',
            },
            {
                title: 'Roles that fit your architecture',
                description:
                    'App, web, worker, database, and cache servers, each installed with exactly what that role needs.',
            },
            {
                title: 'The stack, configured',
                description:
                    'Nginx, PHP-FPM, MySQL, PostgreSQL or MariaDB, Redis, Supervisor, and Node, installed and tuned for production.',
            },
            {
                title: 'Multiple PHP versions',
                description:
                    'Install several PHP versions side by side, set the default, adjust settings, and manage extensions.',
            },
            {
                title: 'Private connections',
                description:
                    'Let web and worker servers reach a database or Redis server, with firewall rules opened only for them.',
            },
            {
                title: 'Live metrics',
                description:
                    'CPU, memory, and disk usage for every server, collected automatically and refreshed on demand.',
            },
        ],
    },
    {
        id: 'deployments',
        title: 'Sites and deployments',
        summary:
            'Push code and let flitops take it from repository to production.',
        items: [
            {
                title: 'Push to deploy',
                description:
                    'Connect GitHub, GitLab, Bitbucket, or any Git remote. Deploy on every push, or by webhook from your CI.',
            },
            {
                title: 'Zero-downtime releases',
                description:
                    'Each deploy builds in a fresh release directory and switches over atomically when it is ready.',
            },
            {
                title: 'One-click rollback',
                description:
                    'Return to any previous release instantly, without rebuilding.',
            },
            {
                title: 'Streaming deploy logs',
                description:
                    'Watch every step of a deployment as it happens, and see exactly where something failed.',
            },
            {
                title: 'Editable deploy scripts',
                description:
                    'Sensible defaults for Laravel, Symfony, WordPress, PHP, and static sites, editable whenever you need more.',
            },
            {
                title: 'Environment management',
                description:
                    'Edit your .env from the dashboard. Values are encrypted at rest and written to the server on save.',
            },
        ],
    },
    {
        id: 'domains',
        title: 'Domains and SSL',
        summary:
            'Certificates that issue themselves and renew before anyone notices.',
        items: [
            {
                title: 'Automatic certificates',
                description:
                    "Free Let's Encrypt certificates for every domain, issued once DNS is verified.",
            },
            {
                title: 'Wildcard domains',
                description:
                    'Issue wildcard certificates and distribute them to every site that needs them.',
            },
            {
                title: 'Renewal you can trust',
                description:
                    'Renewals run well ahead of expiry, and you are alerted if a certificate ever needs attention.',
            },
            {
                title: 'Nginx, editable and versioned',
                description:
                    'Edit site Nginx files in the browser with full history, and restore any earlier version.',
            },
        ],
    },
    {
        id: 'data',
        title: 'Databases and backups',
        summary:
            'The parts of your stack you cannot afford to lose, looked after.',
        items: [
            {
                title: 'Databases and users',
                description:
                    'Create databases and users with scoped permissions, and copy connection details when you need them.',
            },
            {
                title: 'Scheduled backups',
                description:
                    'Back up databases on a schedule to Amazon S3 or Cloudflare R2, with retention you control.',
            },
            {
                title: 'Restore in a click',
                description:
                    'Restore any backup onto its database, or download it to inspect locally.',
            },
            {
                title: 'Import existing data',
                description:
                    'Bring an existing database dump with you and import it straight into a new database.',
            },
        ],
    },
    {
        id: 'operations',
        title: 'Processes and operations',
        summary:
            'Everything that keeps an application running between deploys.',
        items: [
            {
                title: 'Queue workers and daemons',
                description:
                    'Run Horizon, queue workers, Reverb, or any long-running process under Supervisor, restarted on failure.',
            },
            {
                title: 'Scheduled jobs',
                description:
                    'Create, pause, and resume cron jobs without touching a crontab.',
            },
            {
                title: 'Browser terminal',
                description:
                    'Open a full shell on any server from the dashboard, protected by a fresh password confirmation.',
            },
            {
                title: 'Commands and logs',
                description:
                    'Run one-off commands against a site, and read application and server logs without SSH.',
            },
            {
                title: 'Uptime monitoring',
                description:
                    'Every site is checked continuously, so you hear about downtime before your users tell you.',
            },
        ],
    },
    {
        id: 'teams',
        title: 'Teams and access',
        summary:
            'Work together on the same fleet, with the right access for everyone.',
        items: [
            {
                title: 'Teams and invitations',
                description:
                    'Create teams for clients or projects, invite members by email, and switch between them in a click.',
            },
            {
                title: 'Role-based access',
                description:
                    'Owner, admin, developer, and viewer roles decide who can deploy, change servers, or only look.',
            },
            {
                title: 'SSH key management',
                description:
                    'Add your keys once, sync them to servers, and revoke them everywhere at once.',
            },
            {
                title: 'Two-factor authentication',
                description:
                    'Protect every account with an authenticator app and recovery codes.',
            },
        ],
    },
];

export default function Features() {
    return (
        <MarketingLayout
            title="Features"
            description="Everything flitops does to provision servers, deploy applications, and keep them running."
        >
            <PageHero
                eyebrow="Features"
                title="The whole platform, on your own servers"
                description="Everything you would expect from a managed hosting platform, running on infrastructure you own and pay for directly."
            >
                <nav
                    aria-label="Feature sections"
                    className="mt-12 flex flex-wrap gap-x-8 gap-y-3 font-medium"
                >
                    {featureGroups.map((group) => (
                        <a
                            key={group.id}
                            href={`#${group.id}`}
                            className="text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {group.title}
                        </a>
                    ))}
                </nav>
            </PageHero>

            <div className="px-6 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    {featureGroups.map((group) => (
                        <section
                            key={group.id}
                            id={group.id}
                            className="grid scroll-mt-18 gap-10 border-t border-border py-16 sm:py-20 lg:grid-cols-[1fr_2fr] lg:gap-20"
                        >
                            <div className="lg:sticky lg:top-28 lg:self-start">
                                <h2 className="text-3xl font-normal tracking-[-0.02em]">
                                    {group.title}
                                </h2>
                                <p className="mt-4 max-w-sm text-lg leading-relaxed text-muted-foreground">
                                    {group.summary}
                                </p>
                            </div>
                            <dl className="grid gap-x-10 gap-y-10 sm:grid-cols-2">
                                {group.items.map((item) => (
                                    <div key={item.title}>
                                        <dt className="text-lg font-medium">
                                            {item.title}
                                        </dt>
                                        <dd className="mt-2 leading-relaxed text-muted-foreground">
                                            {item.description}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>
            </div>

            <CallToAction />
        </MarketingLayout>
    );
}
