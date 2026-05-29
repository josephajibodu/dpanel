<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Jobs\DeploySiteJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;

class TriggerDeploymentAction
{
    private const IN_PROGRESS_MESSAGE = 'A deployment is already in progress for this site.';

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
            throw new \RuntimeException(self::IN_PROGRESS_MESSAGE);
        }

        try {
            $deployment = $site->deployments()->create([
                'user_id' => $user?->id,
                'status' => DeploymentStatus::Pending,
                'triggered_by' => $triggeredBy,
            ]);
        } catch (QueryException $e) {
            // A concurrent request won the race between the check above and this
            // INSERT; the deployments_active_unique index rejected the duplicate.
            if ($this->isUniqueViolation($e)) {
                throw new \RuntimeException(self::IN_PROGRESS_MESSAGE);
            }

            throw $e;
        }

        $site->update(['deployment_started_at' => now()]);

        DeploySiteJob::dispatch($deployment)->onQueue('deploy');

        return $deployment;
    }

    /**
     * Whether the query failure is a unique-constraint violation.
     * 23505 = PostgreSQL unique_violation; 23000 = SQLite integrity constraint.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23505', '23000'], true);
    }
}
