<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Exceptions\DeploymentFailedException;
use App\Jobs\Concerns\ManagesDeploymentLifecycle;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\ReleaseManager;
use App\Services\PhpRuntimeResolver;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeploySiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ManagesDeploymentLifecycle, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 0;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Deployment $deployment,
    ) {}

    public function handle(SshService $sshService): void
    {
        // Re-fetch from DB to catch any cancellation that arrived while the job was queued.
        $this->deployment->refresh();

        if ($this->deployment->status === DeploymentStatus::Cancelled) {
            Log::info("Deployment {$this->deployment->id} was cancelled before it started — skipping.");

            return;
        }

        $site = $this->deployment->site;
        $server = $site->server;

        Log::info("Starting deployment {$this->deployment->id} for site {$site->domain}");

        // Update status to running
        $this->deployment->update([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
        ]);

        $this->broadcastStatus('started');

        try {
            // Deployment execution (remote)
            $connection = $sshService->connect($server);

            $exitCode = 0;

            if (! $site->repository) {
                $this->logOutput('No repository connected — deployment skipped.', 'info');
            } else {
                $releasePath = $site->releasesPath().'/'.$this->deployment->releaseFolderName();
                $script = $site->deployScript?->script ?? $this->getDefaultScript($site);
                $scriptPath = $this->prepareScript($connection, $script, $site, $releasePath);

                try {
                    $this->logOutput('Starting deployment script...', 'info');
                    $exitCode = $connection->execWithOutput(
                        $scriptPath,
                        fn (string $line) => $this->logOutput($line, 'output'),
                        $this->timeout
                    );
                    $this->logOutput('Deployment script exited with code '.$exitCode, 'info');
                } finally {
                    $this->cleanupScript($connection, $scriptPath);
                }

                if ($exitCode === 0) {
                    $commitInfo = $this->getCommitInfo($connection, $releasePath);

                    if ($commitInfo) {
                        $this->deployment->update([
                            'commit_hash' => $commitInfo['hash'] ?? null,
                            'commit_message' => Str::limit($commitInfo['message'] ?? '', 255),
                            'commit_author' => $commitInfo['author'] ?? null,
                        ]);
                    }

                    app(ReleaseManager::class)->pruneOldReleases($connection, $site, config('server.releases_to_keep'));
                }
            }

            $connection->disconnect();

            if ($exitCode !== 0) {
                throw new DeploymentFailedException("Deployment script exited with code {$exitCode}");
            }

            // Persistence (local DB) — separate so failures don't pollute deployment logs
            try {
                $this->completeDeploymentSuccess($site);
            } catch (\Throwable $e) {
                Log::error("Failed to persist deployment success for {$this->deployment->id}: {$e->getMessage()}");
                throw $e;
            }

            Log::info("Deployment {$this->deployment->id} completed successfully");
        } catch (DeploymentFailedException $e) {
            $this->logOutput("[ERROR] {$e->getMessage()}", 'error');
            Log::error("Deployment {$this->deployment->id} failed: {$e->getMessage()}");
            $this->markDeploymentFailed($site);
            throw $e;
        } catch (QueryException $e) {
            Log::error("Deployment {$this->deployment->id} persistence failed: {$e->getMessage()}");
            $this->markDeploymentFailed($site);
            throw $e;
        } catch (\Throwable $e) {
            $this->logOutput("[ERROR] {$e->getMessage()}", 'error');
            Log::error("Deployment {$this->deployment->id} failed: {$e->getMessage()}");
            $this->markDeploymentFailed($site);
            throw $e;
        }
    }

    /**
     * Get commit information from the release the deploy script just built.
     *
     * @return array<string, string>|null
     */
    private function getCommitInfo($connection, string $releasePath): ?array
    {
        try {
            if (! $connection->directoryExists("{$releasePath}/.git")) {
                return null;
            }

            $commitHash = '';

            try {
                $output = $connection->exec("cd {$releasePath} && git rev-parse HEAD 2>/dev/null", 10);
                $commitHash = trim($output);
            } catch (\Throwable $e) {
                // Git command failed, skip commit info
                return null;
            }

            if (empty($commitHash)) {
                return null;
            }

            $commitMessage = '';
            $commitAuthor = '';

            try {
                $output = $connection->exec("cd {$releasePath} && git log -1 --format='%s' 2>/dev/null", 10);
                $commitMessage = trim($output);
            } catch (\Throwable $e) {
                // Ignore - message is optional
            }

            try {
                $output = $connection->exec("cd {$releasePath} && git log -1 --format='%an' 2>/dev/null", 10);
                $commitAuthor = trim($output);
            } catch (\Throwable $e) {
                // Ignore - author is optional
            }

            return [
                'hash' => $commitHash,
                'message' => $commitMessage,
                'author' => $commitAuthor,
            ];
        } catch (\Throwable $e) {
            Log::warning("Failed to get commit info: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Prepare the deploy script (variable substitution + preamble), upload it to
     * the server, and return the remote path to execute.
     *
     * The uploaded script deletes itself as its first action: once bash has the
     * file open, unlinking it doesn't interrupt execution, so the temp file can't
     * be orphaned by a timeout, dropped connection, or signal mid-deploy.
     */
    private function prepareScript($connection, string $script, Site $site, string $releasePath): string
    {
        $siteRoot = $site->rootPath();
        $runtime = app(PhpRuntimeResolver::class)->forSite($site);
        $phpBinary = $runtime['binary'];
        $phpFpm = $runtime['fpm_service'];
        $composerBin = $runtime['composer'];
        $phpVersion = $site->php_version ?? '8.4';

        // Preamble: set shell variables that default scripts use ($SITE_ROOT is the
        // persistent base dir; $RELEASE_PATH is this deploy's fresh release, and is
        // also where the strategy `cd`s to before the user script runs).
        $serverUser = config('server.user');
        $webUser = config('server.web_user');
        $preamble = "SITE_ROOT='{$siteRoot}'\n";
        $preamble .= "RELEASE_PATH='{$releasePath}'\n";
        $preamble .= "BRANCH='{$site->branch}'\n";
        $preamble .= "PHP='{$phpBinary}'\n";
        $preamble .= "COMPOSER='{$composerBin}'\n";
        $preamble .= "PHP_FPM='{$phpFpm}'\n";
        $preamble .= "SERVER_USER='{$serverUser}'\n";
        $preamble .= "WEB_USER='{$webUser}'\n\n";

        // Replace placeholder variables in script ({{SITE_PATH}}, {{BRANCH}}, etc.)
        // {{WEB_ROOT}} points at the release being built, not $site->webRoot()
        // (which resolves through `current` — still the OLD release at this point,
        // since the symlink swap only happens after the script succeeds).
        $directory = $site->directory ?: '/';
        $releaseWebRoot = rtrim($releasePath, '/').'/'.ltrim($directory, '/');

        $replacements = [
            '{{SITE_PATH}}' => $releasePath,
            '{{WEB_ROOT}}' => $releaseWebRoot,
            '{{BRANCH}}' => $site->branch,
            '{{DOMAIN}}' => $site->domain,
            '{{PHP_VERSION}}' => $phpVersion,
        ];

        $strategy = app(DeploymentStrategy::class);
        $userScript = str_replace(array_keys($replacements), array_values($replacements), $script);
        $prepared = $preamble.$strategy->prepend($site).$userScript.$strategy->append($site);

        $scriptPath = '/tmp/deploy_'.uniqid().'.sh';

        // Self-delete on the first line so the temp file is gone the moment the
        // script starts running, regardless of how the run later terminates.
        $contents = "#!/bin/bash\nset -e\nrm -f '{$scriptPath}'\n\n{$prepared}";

        $connection->upload($contents, $scriptPath);
        $connection->exec("chmod +x '{$scriptPath}'");

        return $scriptPath;
    }

    /**
     * Best-effort removal of the remote deploy script.
     *
     * The script also deletes itself on start; this safety net covers the narrow
     * window where it was uploaded but the run never began (e.g. the job died first).
     */
    private function cleanupScript($connection, string $scriptPath): void
    {
        try {
            $connection->exec("rm -f '{$scriptPath}'");
        } catch (\Throwable $e) {
            Log::warning("Failed to remove remote deploy script {$scriptPath}: {$e->getMessage()}");
        }
    }

    /**
     * Get default deploy script for site if none exists.
     */
    private function getDefaultScript($site): string
    {
        return $site->project_type->defaultDeployScript();
    }
}
