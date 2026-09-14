<?php

namespace App\Actions\Sites;

use App\Enums\DeploymentStatus;
use App\Jobs\RollbackSiteJob;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;

class RollbackDeploymentAction
{
    private const IN_PROGRESS_MESSAGE = 'A deployment is already in progress for this site.';

    /**
     * Roll back a site to an earlier finished deployment's release by
     * creating a new Deployment row that reuses that release, then
     * dispatching RollbackSiteJob to swap `current` onto it.
     *
     * @throws \RuntimeException when the target isn't a valid rollback candidate,
     *                           or a deployment is already in progress.
     */
    public function execute(Site $site, Deployment $target, ?User $user = null): Deployment
    {
        if ($target->site_id !== $site->id) {
            throw new \RuntimeException('That deployment does not belong to this site.');
        }

        if ($target->status !== DeploymentStatus::Finished) {
            throw new \RuntimeException('Only a finished deployment can be rolled back to.');
        }

        $inProgress = $site->deployments()
            ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
            ->exists();

        if ($inProgress) {
            throw new \RuntimeException(self::IN_PROGRESS_MESSAGE);
        }

        try {
            $rollback = $site->deployments()->create([
                'user_id' => $user?->id,
                'status' => DeploymentStatus::Pending,
                'triggered_by' => 'rollback',
                'rollback_of_deployment_id' => $target->id,
                'release_path' => $target->releaseFolderName(),
                'commit_hash' => $target->commit_hash,
                'commit_message' => $target->commit_message,
                'commit_author' => $target->commit_author,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new \RuntimeException(self::IN_PROGRESS_MESSAGE);
            }

            throw $e;
        }

        $site->update(['deployment_started_at' => now()]);

        RollbackSiteJob::dispatch($rollback)->onQueue('deploy');

        return $rollback;
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
