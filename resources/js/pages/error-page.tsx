import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

const titles: Record<number, string> = {
    403: "You don't have access",
    404: 'Page not found',
    419: 'Page expired',
    429: 'Too many requests',
    500: 'Something went wrong',
    503: 'Down for maintenance',
};

const descriptions: Record<number, string> = {
    403: "You don't have permission to view this page.",
    404: "The page you're looking for doesn't exist or may have been deleted.",
    419: 'Your session timed out. Refresh the page and try again.',
    429: "You've made too many requests. Wait a minute and try again.",
    500: 'Something went wrong on our end. Please try again in a moment.',
    503: "We're doing some quick maintenance. Please try again in a few minutes.",
};

/**
 * Shown in production when a page fails to load, in place of Laravel's
 * plain error page. A 403 carries its own message when it has one, such as
 * the plan upgrade message.
 */
export default function ErrorPage({
    status,
    message,
}: {
    status: number;
    message: string;
}) {
    const title = titles[status] ?? 'Something went wrong';

    return (
        <AuthLayout
            title={title}
            description={
                message ||
                descriptions[status] ||
                'Something went wrong. Please try again.'
            }
        >
            <Head title={title} />

            <div className="flex justify-center gap-2">
                <Button variant="outline" onClick={() => window.history.back()}>
                    Go back
                </Button>
                <Button asChild>
                    <Link href="/dashboard">Go to dashboard</Link>
                </Button>
            </div>
        </AuthLayout>
    );
}
