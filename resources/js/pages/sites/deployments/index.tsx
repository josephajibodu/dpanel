import { DeploymentList } from '@/components/deployments/deployment-list';
import { Button } from '@/components/ui/button';
import {
    getPaginationUrls,
    Pagination,
    type PaginationMeta,
} from '@/components/ui/pagination';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Deployment } from '@/types/deployment';
import { Site } from '@/types/site';
import { Head, router } from '@inertiajs/react';
import { Loader2Icon, RocketIcon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
        };
    };
    deployments: {
        data: Deployment[];
        links?: unknown;
        meta?: PaginationMeta;
    };
}

export default function SiteDeploymentsIndex({ server: serverProp, site: siteProp, deployments }: Props) {
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;
    const [isDeploying, setIsDeploying] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server?.name || site.server?.name || 'Server', href: `/servers/${serverId}` },
        { title: site.domain, href: `/servers/${serverId}/sites/${site.id}` },
        { title: 'Deployments', href: `/servers/${serverId}/sites/${site.id}/deployments` },
    ];

    const handleDeploy = () => {
        setIsDeploying(true);
        router.post(`/servers/${serverId}/sites/${site.id}/deployments`, {}, {
            onFinish: () => {
                setIsDeploying(false);
            },
        });
    };

    const deploymentList = deployments.data ?? [];
    const { prevUrl, nextUrl } = getPaginationUrls(deployments.links);

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(String(serverId ?? ''), site.id)}
        >
            <Head title={`Deployments - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Deployment History
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Recent deployments for this site.
                        </p>
                    </div>
                    <Button
                        onClick={handleDeploy}
                        disabled={isDeploying || site.status === 'installing'}
                    >
                        {isDeploying ? (
                            <>
                                <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />
                                Deploying...
                            </>
                        ) : (
                            <>
                                <RocketIcon className="mr-2 h-4 w-4" />
                                Deploy Now
                            </>
                        )}
                    </Button>
                </div>

                <div className="space-y-4">
                    <DeploymentList
                        deployments={deploymentList}
                        serverId={Number(serverId)}
                        siteId={site.id}
                    />
                    {(prevUrl || nextUrl) && (
                        <Pagination
                            prevUrl={prevUrl}
                            nextUrl={nextUrl}
                            meta={deployments.meta}
                        />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
