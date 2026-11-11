import CallToAction from '@/components/marketing/call-to-action';
import PageHero from '@/components/marketing/page-hero';
import SectionHeading from '@/components/marketing/section-heading';
import MarketingLayout from '@/layouts/marketing-layout';

const beliefs = [
    {
        title: 'You should own your infrastructure',
        description:
            'Managed platforms are convenient until the bill arrives or the rules change. Your servers, in your cloud account, at your provider’s price, should be the default.',
    },
    {
        title: 'Owning it should not mean babysitting it',
        description:
            'Provisioning, certificates, deploys, backups, and workers are solved problems. Nobody should have to solve them again by hand at two in the morning.',
    },
    {
        title: 'No lock-in, ever',
        description:
            'Everything we set up is standard and inspectable. If you leave, your servers keep running exactly as they were. We would rather earn your business every month.',
    },
    {
        title: 'Careful beats clever',
        description:
            'We have the keys to your servers. That means boring, well-tested defaults, secrets handled with care, and never surprising you in production.',
    },
];

export default function About() {
    return (
        <MarketingLayout
            title="About"
            description="flitops exists so teams can own their infrastructure without needing an operations team to run it."
        >
            <PageHero
                eyebrow="About"
                title="Infrastructure you own, without the overhead"
                description="flitops gives developers and teams the experience of a managed platform on servers they control and pay for directly."
            />

            <section className="px-6 lg:px-8">
                <div className="mx-auto grid max-w-7xl gap-10 border-t border-border pt-14 lg:grid-cols-[1fr_2fr] lg:gap-20">
                    <h2 className="text-3xl font-normal tracking-[-0.02em]">
                        Why we built it
                    </h2>
                    <div className="max-w-3xl space-y-6 text-xl leading-relaxed text-muted-foreground">
                        <p>
                            <span className="text-foreground">
                                Most teams face the same choice.
                            </span>{' '}
                            Rent a managed platform and accept its pricing,
                            limits, and opinions, or run your own servers and
                            become a part-time systems administrator.
                        </p>
                        <p>
                            We built flitops to remove that trade-off. It
                            handles the work an operations team would do,
                            provisioning hardened servers, deploying every push
                            without downtime, issuing certificates, backing up
                            databases, and keeping workers alive, while the
                            servers themselves stay in your cloud account.
                        </p>
                        <p>
                            The result is the polish of a platform with the
                            economics and freedom of owning your stack.
                        </p>
                    </div>
                </div>
            </section>

            <section className="px-6 pt-24 sm:pt-32 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    <SectionHeading title="The principles behind the product" />
                    <div className="mt-16 grid gap-x-10 gap-y-14 sm:grid-cols-2 lg:gap-x-20">
                        {beliefs.map((belief, index) => (
                            <div
                                key={belief.title}
                                className="border-t border-foreground pt-6"
                            >
                                <span className="text-sm font-medium text-muted-foreground tabular-nums">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <h3 className="mt-10 text-2xl font-medium tracking-tight">
                                    {belief.title}
                                </h3>
                                <p className="mt-3 max-w-xl leading-relaxed text-muted-foreground">
                                    {belief.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <CallToAction />
        </MarketingLayout>
    );
}
