import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

import MarketingFooter from '@/components/marketing/marketing-footer';
import MarketingHeader from '@/components/marketing/marketing-header';

/**
 * The shell shared by every public page: header, footer, and page metadata.
 */
export default function MarketingLayout({
    title,
    description,
    canRegister = true,
    children,
}: {
    title: string;
    description: string;
    canRegister?: boolean;
    children: ReactNode;
}) {
    return (
        <>
            <Head title={title}>
                <meta
                    head-key="description"
                    name="description"
                    content={description}
                />
                <meta head-key="og:title" property="og:title" content={title} />
                <meta
                    head-key="og:description"
                    property="og:description"
                    content={description}
                />
            </Head>

            <div className="relative isolate min-h-screen [--text-sm--line-height:calc(1.25/0.875)] [--text-sm:0.875rem] overflow-x-clip bg-background text-foreground antialiased">
                <MarketingHeader canRegister={canRegister} />
                <main>{children}</main>
                <MarketingFooter />
            </div>
        </>
    );
}
