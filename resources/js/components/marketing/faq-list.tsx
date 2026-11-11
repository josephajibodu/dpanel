import { PlusIcon } from '@phosphor-icons/react';

export type FaqItem = {
    question: string;
    answer: string;
};

/**
 * A list of questions that expand to show their answer, built on native details/summary.
 */
export default function FaqList({ items }: { items: FaqItem[] }) {
    return (
        <div className="divide-y divide-border border-y border-border">
            {items.map((item) => (
                <details key={item.question} className="group py-1">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-6 py-5 text-left [&::-webkit-details-marker]:hidden">
                        <h3 className="font-medium">{item.question}</h3>
                        <PlusIcon className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-45" />
                    </summary>
                    <p className="max-w-2xl pb-6 leading-relaxed text-muted-foreground">
                        {item.answer}
                    </p>
                </details>
            ))}
        </div>
    );
}
