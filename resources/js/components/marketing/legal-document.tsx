import { type ReactNode } from 'react';

export type LegalSection = {
    id: string;
    title: string;
    body: ReactNode;
};

/**
 * A long-form legal page: a title band, a sticky table of contents, and numbered sections.
 */
export default function LegalDocument({
    title,
    effectiveDate,
    summary,
    sections,
}: {
    title: string;
    effectiveDate: string;
    summary: ReactNode;
    sections: LegalSection[];
}) {
    return (
        <div className="px-6 pt-20 pb-24 sm:pt-28 sm:pb-32">
            <div className="mx-auto max-w-6xl">
                <header className="max-w-3xl border-b border-border pb-10">
                    <p className="text-sm font-medium text-brand dark:text-blue-400">
                        Legal
                    </p>
                    <h1 className="mt-3 text-4xl font-semibold tracking-[-0.025em] sm:text-5xl">
                        {title}
                    </h1>
                    <p className="mt-4 text-sm text-muted-foreground">
                        Effective {effectiveDate}
                    </p>
                    <div className="mt-6 text-lg leading-relaxed text-muted-foreground">
                        {summary}
                    </div>
                </header>

                <div className="mt-14 grid gap-14 lg:grid-cols-[220px_1fr]">
                    <nav aria-label="On this page" className="hidden lg:block">
                        <div className="sticky top-24">
                            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                On this page
                            </p>
                            <ol className="mt-4 space-y-2.5 text-sm">
                                {sections.map((section) => (
                                    <li key={section.id}>
                                        <a
                                            href={`#${section.id}`}
                                            className="text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            {section.title}
                                        </a>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </nav>

                    <div className="max-w-3xl space-y-12">
                        {sections.map((section, index) => (
                            <section
                                key={section.id}
                                id={section.id}
                                className="scroll-mt-24"
                            >
                                <h2 className="text-xl font-semibold tracking-tight">
                                    <span className="mr-3 font-log text-sm text-muted-foreground">
                                        {index + 1}.
                                    </span>
                                    {section.title}
                                </h2>
                                <div className="mt-4 space-y-4 leading-relaxed text-muted-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 [&_li]:pl-1 [&_strong]:font-medium [&_strong]:text-foreground [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5">
                                    {section.body}
                                </div>
                            </section>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
