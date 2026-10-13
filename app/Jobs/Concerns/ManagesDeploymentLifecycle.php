<?php

namespace App\Jobs\Concerns;

use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Events\DeploymentOutput as DeploymentOutputEvent;
use App\Events\DeploymentStatusChanged;
use App\Models\Site;
use App\Services\PhpRuntimeResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared status/log/broadcast plumbing for jobs that carry a Deployment
 * through its lifecycle (DeploySiteJob, RollbackSiteJob), plus the shell
 * variable preamble their remote scripts run with. Requires a public
 * `Deployment $deployment` property on the using class.
 */
trait ManagesDeploymentLifecycle
{
    /**
     * Shell variables the deploy script and the strategy's fragments rely on
     * ($SITE_ROOT is the persistent base dir; $RELEASE_PATH is the release
     * being built or rolled back to).
     */
    private function scriptPreamble(Site $site, string $releasePath): string
    {
        $runtime = app(PhpRuntimeResolver::class)->forSite($site);
        $serverUser = config('server.user');
        $webUser = config('server.web_user');

        $preamble = "SITE_ROOT='{$site->rootPath()}'\n";
        $preamble .= "RELEASE_PATH='{$releasePath}'\n";
        $preamble .= "BRANCH='{$site->branch}'\n";
        $preamble .= "PHP='{$runtime['binary']}'\n";
        $preamble .= "COMPOSER='{$runtime['composer']}'\n";
        $preamble .= "PHP_FPM='{$runtime['fpm_service']}'\n";
        $preamble .= "SERVER_USER='{$serverUser}'\n";
        $preamble .= "WEB_USER='{$webUser}'\n\n";

        return $preamble;
    }

    /**
     * Log output line to database and broadcast.
     */
    private function logOutput(string $line, string $type = 'output'): void
    {
        $this->deployment->logs()->create([
            'type' => $type,
            'message' => $line,
            'created_at' => now(),
        ]);

        Log::debug('Broadcasting deployment output event', [
            'deployment_id' => $this->deployment->id,
            'type' => $type,
            'line_preview' => Str::limit($line, 120),
        ]);

        broadcast(new DeploymentOutputEvent(
            deployment: $this->deployment,
            line: $line,
            type: $type,
        ));
    }

    /**
     * Broadcast deployment status change.
     */
    private function broadcastStatus(string $event): void
    {
        Log::debug('Broadcasting deployment status event', [
            'deployment_id' => $this->deployment->id,
            'status_event' => $event,
            'status' => $this->deployment->status->value,
        ]);

        broadcast(new DeploymentStatusChanged(
            deployment: $this->deployment,
            event: $event,
        ));
    }

    /**
     * Persist deployment success and update site status.
     */
    private function completeDeploymentSuccess(Site $site): void
    {
        $this->deployment->update([
            'status' => DeploymentStatus::Finished,
            'finished_at' => now(),
            'duration_seconds' => (int) now()->diffInSeconds($this->deployment->started_at, true),
        ]);

        $site->update([
            'status' => $site->repository ? SiteStatus::Deployed : SiteStatus::Provisioned,
            'deployment_finished_at' => now(),
        ]);

        $this->logOutput('Deployment completed successfully!', 'success');
        $this->broadcastStatus('finished');
    }

    /**
     * Persist deployment failure and update site status.
     */
    private function markDeploymentFailed(Site $site): void
    {
        $this->deployment->update([
            'status' => DeploymentStatus::Failed,
            'finished_at' => now(),
            'duration_seconds' => $this->deployment->started_at
                ? (int) now()->diffInSeconds($this->deployment->started_at, true)
                : 0,
        ]);

        $site->update(['status' => SiteStatus::Failed]);
        $this->broadcastStatus('failed');
    }

    /**
     * Handle job failure (timeout, worker crash, etc.) — ensure status is updated.
     */
    public function failed(\Throwable $e): void
    {
        Log::error(class_basename($this)." failed for deployment {$this->deployment->id}: {$e->getMessage()}");

        $this->markDeploymentFailed($this->deployment->site);
    }
}
