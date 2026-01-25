<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Site;

class TriggerDeploymentAction
{
    /**
     * Trigger a new deployment for the given site.
     */
    public function execute(Site $site, string $triggeredBy = 'manual'): Deployment
    {
        // Create deployment record
        $deployment = $site->deployments()->create([
            'user_id' => auth()->id(),
            'status' => DeploymentStatus::Pending,
            'triggered_by' => $triggeredBy,
        ]);

        // Update site deployment timestamps
        $site->update([
            'deployment_started_at' => now(),
        ]);

        // Dispatch deployment job
        DeploySiteJob::dispatch($deployment)->onQueue('deploy');

        return $deployment;
    }
}
