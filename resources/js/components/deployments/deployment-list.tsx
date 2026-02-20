import { DeploymentCard } from './deployment-card';
import { Deployment } from '@/types/deployment';
import { EmptyState } from '@/components/empty-state';
import { RocketIcon } from 'lucide-react';

interface DeploymentListProps {
    deployments: Deployment[];
    serverId: number;
    siteId: number;
}

export function DeploymentList({ deployments, serverId, siteId }: DeploymentListProps) {
    if (deployments.length === 0) {
        return (
            <EmptyState
                icon={RocketIcon}
                title="No deployments yet"
                description="Deployments will appear here once you trigger your first deployment."
            />
        );
    }

    return (
        <div className="space-y-4">
            {deployments.map((deployment) => (
                <DeploymentCard
                    key={deployment.id}
                    deployment={deployment}
                    serverId={serverId}
                    siteId={siteId}
                />
            ))}
        </div>
    );
}
