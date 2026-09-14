<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Exceptions\DeploymentFailedException;
use App\Jobs\Concerns\ManagesDeploymentLifecycle;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rolls back to an already-built release by swapping the `current` symlink —
 * no clone, no build, no migrations. That's the entire point: rollback is
 * fast and doesn't repeat whatever the original deploy did.
 */
class RollbackSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ManagesDeploymentLifecycle, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 0;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Deployment $deployment,
    ) {}

    public function handle(SshService $sshService, DeploymentStrategy $strategy): void
    {
        $this->deployment->refresh();

        if ($this->deployment->status === DeploymentStatus::Cancelled) {
            Log::info("Rollback {$this->deployment->id} was cancelled before it started — skipping.");

            return;
        }

        $site = $this->deployment->site;
        $releasePath = $site->releasesPath().'/'.$this->deployment->releaseFolderName();

        Log::info("Starting rollback {$this->deployment->id} for site {$site->domain} to release {$this->deployment->releaseFolderName()}");

        $this->deployment->update([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
        ]);

        $this->broadcastStatus('started');

        try {
            $connection = $sshService->connect($site->server);

            try {
                if (! $connection->directoryExists($releasePath)) {
                    throw new DeploymentFailedException(
                        "Release {$this->deployment->releaseFolderName()} no longer exists on the server (it may have been pruned) — cannot roll back to it."
                    );
                }

                $this->logOutput("Rolling back to release {$this->deployment->releaseFolderName()}...", 'info');
                $connection->exec($strategy->swapAndReload($site, escapeshellarg($releasePath)), timeout: $this->timeout);
                $this->logOutput('Rollback complete.', 'info');
            } finally {
                $connection->disconnect();
            }

            $this->completeDeploymentSuccess($site);

            Log::info("Rollback {$this->deployment->id} completed successfully");
        } catch (DeploymentFailedException $e) {
            $this->logOutput("[ERROR] {$e->getMessage()}", 'error');
            Log::error("Rollback {$this->deployment->id} failed: {$e->getMessage()}");
            $this->markDeploymentFailed($site);
            throw $e;
        } catch (QueryException $e) {
            Log::error("Rollback {$this->deployment->id} persistence failed: {$e->getMessage()}");
            $this->markDeploymentFailed($site);
            throw $e;
        } catch (\Throwable $e) {
            $this->logOutput("[ERROR] {$e->getMessage()}", 'error');
            Log::error("Rollback {$this->deployment->id} failed: {$e->getMessage()}");
            $this->markDeploymentFailed($site);
            throw $e;
        }
    }
}
