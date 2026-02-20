import { SiteStatusBadge } from '@/components/sites/site-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Site } from '@/types/site';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeftIcon, FileCodeIcon, Loader2Icon } from 'lucide-react';

interface Props {
    site: {
        data: Site & {
            server?: {
                id: number;
                name: string;
                ip_address: string;
            };
            deploy_script?: string;
        };
    };
}

export default function SiteDeployScriptShow({ site: siteProp }: Props) {
    const site = siteProp.data;
    const form = useForm({
        script: site.deploy_script ?? '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/sites/${site.id}/deploy-script`);
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: site.server?.name || 'Server', href: `/servers/${site.server?.id}` },
        { title: site.domain, href: `/sites/${site.id}` },
        { title: 'Deploy Script', href: `/sites/${site.id}/deploy-script` },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(site.server?.id ?? '', site.id)}
        >
            <Head title={`Deploy Script - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={`/sites/${site.id}`}>
                            <ArrowLeftIcon className="h-4 w-4" />
                        </Link>
                    </Button>
                    <div className="flex-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">{site.domain}</h1>
                            <SiteStatusBadge status={site.status} statusLabel={site.status_label} statusColor={site.status_color} />
                        </div>
                        <p className="text-muted-foreground text-sm">Deploy script</p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileCodeIcon className="h-5 w-5" />
                            Deploy Script
                        </CardTitle>
                        <CardDescription>
                            Customize the deployment script that runs when you deploy your site. Available variables: $SITE_ROOT, $BRANCH, $PHP, $COMPOSER, $PHP_FPM
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="script">Deployment Script</Label>
                                <Textarea
                                    id="script"
                                    value={form.data.script}
                                    onChange={(e) => form.setData('script', e.target.value)}
                                    className="min-h-[400px] font-mono text-sm"
                                    placeholder="cd $SITE_ROOT&#10;git pull origin $BRANCH&#10;$COMPOSER install --no-dev"
                                />
                                {form.errors.script && <p className="text-destructive text-sm">{form.errors.script}</p>}
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                    Save Script
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
