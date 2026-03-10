<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Events\DeploymentOutput as DeploymentOutputEvent;
use App\Events\DeploymentStatusChanged;
use App\Exceptions\DeploymentFailedException;
use App\Models\Deployment;
use App\Models\Site;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 0;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Deployment $deployment,
    ) {}

    public function handle(SshService $sshService): void
    {
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

            $this->logOutput('Fetching latest commit information...', 'info');
            $commitInfo = $this->getCommitInfo($connection, $site);

            if ($commitInfo) {
                $this->deployment->update([
                    'commit_hash' => $commitInfo['hash'] ?? null,
                    'commit_message' => Str::limit($commitInfo['message'] ?? '', 255),
                    'commit_author' => $commitInfo['author'] ?? null,
                ]);
            }

            $exitCode = 0;

            if (! $site->repository) {
                $this->logOutput('No repository connected — deployment skipped.', 'info');
            } else {
                $script = $site->deployScript?->script ?? $this->getDefaultScript($site);
                $preparedScript = $this->prepareScript($connection, $script, $site);

                $this->logOutput('Starting deployment script...', 'info');
                $exitCode = $connection->execWithOutput(
                    $preparedScript,
                    fn (string $line) => $this->logOutput($line, 'output'),
                    $this->timeout
                );
                $this->logOutput('Deployment script exited with code '.$exitCode, 'info');
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
     * Get commit information from git repository.
     *
     * @return array<string, string>|null
     */
    private function getCommitInfo($connection, $site): ?array
    {
        try {
            $siteRoot = $site->rootPath();

            // Check if git repository exists
            if (! $connection->directoryExists("{$siteRoot}/.git")) {
                return null;
            }

            // Get commit hash, message, and author
            // Use execWithOutput with a timeout to avoid hanging, and catch any errors
            $commitHash = '';
            $commitMessage = '';
            $commitAuthor = '';

            try {
                $output = $connection->exec("cd {$siteRoot} && git rev-parse HEAD 2>/dev/null", 10);
                $commitHash = trim($output);
            } catch (\Throwable $e) {
                // Git command failed, skip commit info
                return null;
            }

            if (empty($commitHash)) {
                return null;
            }

            try {
                $output = $connection->exec("cd {$siteRoot} && git log -1 --format='%s' 2>/dev/null", 10);
                $commitMessage = trim($output);
            } catch (\Throwable $e) {
                // Ignore - message is optional
            }

            try {
                $output = $connection->exec("cd {$siteRoot} && git log -1 --format='%an' 2>/dev/null", 10);
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
     * Prepare deploy script by replacing variables and upload to server.
     * Prepends variable assignments so $SITE_ROOT, $BRANCH, $PHP, etc. are available in the script.
     */
    private function prepareScript($connection, string $script, $site): string
    {
        $siteRoot = $site->rootPath();
        $webRoot = $site->webRoot();
        $phpVersion = $site->php_version ?? '8.4';
        $phpBinary = "php{$phpVersion}";
        $phpFpm = "php{$phpVersion}-fpm";

        // Preamble: set shell variables that default scripts use ($SITE_ROOT, $BRANCH, $PHP, etc.)
        $preamble = "SITE_ROOT='{$siteRoot}'\n";
        $preamble .= "WEB_ROOT='{$webRoot}'\n";
        $preamble .= "BRANCH='{$site->branch}'\n";
        $preamble .= "PHP='{$phpBinary}'\n";
        $preamble .= "COMPOSER='composer'\n";
        $preamble .= "PHP_FPM='{$phpFpm}'\n\n";

        // Replace placeholder variables in script ({{SITE_PATH}}, {{BRANCH}}, etc.)
        $replacements = [
            '{{SITE_PATH}}' => $siteRoot,
            '{{WEB_ROOT}}' => $webRoot,
            '{{BRANCH}}' => $site->branch,
            '{{DOMAIN}}' => $site->domain,
            '{{PHP_VERSION}}' => $phpVersion,
        ];

        $prepared = $preamble.str_replace(array_keys($replacements), array_values($replacements), $script);

        // Create a temporary script file and execute it
        $scriptPath = '/tmp/deploy_'.uniqid().'.sh';

        $connection->upload("#!/bin/bash\nset -e\n\n{$prepared}", $scriptPath);
        $connection->exec("chmod +x {$scriptPath}");

        return "{$scriptPath}; EXIT_CODE=\$?; rm -f {$scriptPath}; exit \$EXIT_CODE";
    }

    /**
     * Get default deploy script for site if none exists.
     */
    private function getDefaultScript($site): string
    {
        return $site->project_type->defaultDeployScript();
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
        Log::error("DeploySiteJob failed for deployment {$this->deployment->id}: {$e->getMessage()}");

        $this->markDeploymentFailed($this->deployment->site);
    }
}
