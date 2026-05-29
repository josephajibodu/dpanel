<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;

class CancelDeploymentAction
{
    /**
     * Cancel a pending deployment.
     *
     * @throws \RuntimeException if the deployment is already running or not cancellable.
     */
    public function execute(Deployment $deployment): void
    {
        if ($deployment->status === DeploymentStatus::Running) {
            throw new \RuntimeException('A deployment that is already running cannot be cancelled.');
        }

        if ($deployment->status !== DeploymentStatus::Pending) {
            throw new \RuntimeException('Only pending deployments can be cancelled.');
        }

        $deployment->update(['status' => DeploymentStatus::Cancelled]);
    }
}
