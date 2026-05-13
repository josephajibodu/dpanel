<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;

class TriggerDeploymentAction
{
    /**
     * Trigger a new deployment for the given site.
     *
     * @throws \RuntimeException when a deployment is already in progress.
     */
    public function execute(Site $site, string $triggeredBy = 'manual', ?User $user = null): Deployment
    {
        $inProgress = $site->deployments()
            ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
            ->exists();

        if ($inProgress) {
            throw new \RuntimeException('A deployment is already in progress for this site.');
        }

        $deployment = $site->deployments()->create([
            'user_id' => $user?->id,
            'status' => DeploymentStatus::Pending,
            'triggered_by' => $triggeredBy,
        ]);

        $site->update(['deployment_started_at' => now()]);

        DeploySiteJob::dispatch($deployment)->onQueue('deploy');

        return $deployment;
    }
}
