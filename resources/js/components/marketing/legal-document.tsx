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
        <div className="px-6 pt-16 pb-24 sm:pt-24 sm:pb-32 lg:px-8">
            <div className="mx-auto max-w-7xl">
                <header className="max-w-3xl">
                    <p className="font-log text-xs font-medium tracking-[0.08em] text-brand uppercase dark:text-blue-400">
                        Legal
                    </p>
                    <h1 className="mt-5 text-[2.75rem] leading-[1.02] font-medium tracking-[-0.035em] sm:text-7xl">
                        {title}
                    </h1>
                    <p className="mt-7 text-sm text-muted-foreground">
                        Effective {effectiveDate}
                    </p>
                    <div className="mt-4 text-lg leading-relaxed text-muted-foreground sm:text-xl">
                        {summary}
                    </div>
                </header>

                <div className="mt-16 grid gap-14 border-t border-border pt-14 lg:grid-cols-[1fr_2fr] lg:gap-20">
                    <nav aria-label="On this page" className="hidden lg:block">
                        <div className="sticky top-28">
                            <p className="text-sm text-muted-foreground">
                                On this page
                            </p>
                            <ol className="mt-5 space-y-3 font-medium">
                                {sections.map((section) => (
                                    <li key={section.id}>
                                        <a
                                            href={`#${section.id}`}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {section.title}
                                        </a>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </nav>

                    <div className="max-w-3xl space-y-14">
                        {sections.map((section, index) => (
                            <section
                                key={section.id}
                                id={section.id}
                                className="scroll-mt-24"
                            >
                                <h2 className="text-2xl font-medium tracking-tight">
                                    <span className="mr-3 text-muted-foreground tabular-nums">
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
