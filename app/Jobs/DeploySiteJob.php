<?php

namespace App\Jobs;

use App\Actions\Database\EscapesShell;
use App\Actions\Database\KeepsSecretsOffCommandLine;
use App\Enums\DeploymentStatus;
use App\Exceptions\DeploymentFailedException;
use App\Jobs\Concerns\ManagesDeploymentLifecycle;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\ReleaseManager;
use App\Services\Ssh\SshConnection;
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
    use Dispatchable, EscapesShell, InteractsWithQueue, KeepsSecretsOffCommandLine, ManagesDeploymentLifecycle, Queueable, SerializesModels;

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

                $exitCode = $site->isIsolated()
                    ? $this->deployIsolatedSite($connection, $script, $site, $releasePath)
                    : $this->deploySite($connection, $script, $site, $releasePath);

                if ($exitCode === 0) {
                    $commitInfo = $this->getCommitInfo($connection, $site, $releasePath);

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
     * Run the deploy script as the server user, which owns sites that
     * aren't isolated.
     */
    private function deploySite(SshConnection $connection, string $script, Site $site, string $releasePath): int
    {
        $exitCode = 0;

        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $script, $site, $releasePath, &$exitCode): void {
            $scriptPath = $this->prepareScript($connection, $directory, $script, $site, $releasePath);

            $this->logOutput('Starting deployment script...', 'info');
            $exitCode = $connection->execWithOutput(
                'bash '.$this->escapeForShell($scriptPath),
                fn (string $line) => $this->logOutput($line, 'output'),
                $this->timeout
            );
            $this->logOutput('Deployment script exited with code '.$exitCode, 'info');
        });

        return $exitCode;
    }

    /**
     * Deploy an isolated site in three parts: FlitOps clones the code as the
     * server user (so the site's user never holds a git key), the deploy
     * script runs as the site's user, then FlitOps reloads PHP-FPM, which
     * needs sudo the site's user doesn't have.
     *
     * The script reaches the site's user through a pipe from a private
     * directory, never a shared /tmp path, and the user saves it to a temp
     * file of its own before running it.
     */
    private function deployIsolatedSite(SshConnection $connection, string $script, Site $site, string $releasePath): int
    {
        $strategy = app(DeploymentStrategy::class);
        $preamble = "set -e\n".$this->scriptPreamble($site, $releasePath);

        $this->logOutput('Cloning repository...', 'info');
        $exitCode = $connection->execWithOutput(
            $preamble.$strategy->cloneForIsolatedSite($site),
            fn (string $line) => $this->logOutput($line, 'output'),
            $this->timeout
        );

        if ($exitCode !== 0) {
            $this->logOutput('Cloning the repository exited with code '.$exitCode, 'info');

            return $exitCode;
        }

        $this->withPrivateDirectory($connection, function (string $directory) use ($connection, $script, $site, $releasePath, &$exitCode): void {
            $scriptPath = "{$directory}/deploy.sh";
            $connection->upload("#!/bin/bash\nset -e\n\n".$this->buildScript($script, $site, $releasePath), $scriptPath);

            $runAsSiteUser = $site->commandAsSystemUser(
                'script=$(mktemp) && cat > "$script" && { bash "$script"; status=$?; rm -f "$script"; exit $status; }'
            );

            $this->logOutput('Starting deployment script...', 'info');
            $exitCode = $connection->execWithOutput(
                'cat '.$this->escapeForShell($scriptPath).' | '.$runAsSiteUser,
                fn (string $line) => $this->logOutput($line, 'output'),
                $this->timeout
            );
            $this->logOutput('Deployment script exited with code '.$exitCode, 'info');
        });

        if ($exitCode === 0) {
            $this->logOutput('Restarting FPM...', 'output');
            $connection->exec($preamble.$strategy->reloadPhpFpm($site), 60);
        }

        return $exitCode;
    }

    /**
     * Get commit information from the release the deploy script just built.
     *
     * @return array<string, string>|null
     */
    private function getCommitInfo($connection, Site $site, string $releasePath): ?array
    {
        try {
            $hasGitDirectory = $site->isIsolated()
                ? trim($connection->exec($site->commandAsSystemUser("test -d {$releasePath}/.git && echo yes || true"), 10)) === 'yes'
                : $connection->directoryExists("{$releasePath}/.git");

            if (! $hasGitDirectory) {
                return null;
            }

            $commitHash = '';

            try {
                $output = $connection->exec($site->commandAsSystemUser("cd {$releasePath} && git rev-parse HEAD 2>/dev/null"), 10);
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
                $output = $connection->exec($site->commandAsSystemUser("cd {$releasePath} && git log -1 --format='%s' 2>/dev/null"), 10);
                $commitMessage = trim($output);
            } catch (\Throwable $e) {
                // Ignore - message is optional
            }

            try {
                $output = $connection->exec($site->commandAsSystemUser("cd {$releasePath} && git log -1 --format='%an' 2>/dev/null"), 10);
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
     * Prepare the deploy script (variable substitution + preamble), upload it
     * into the private directory, and return the remote path to execute.
     *
     * The uploaded script deletes itself as its first action: once bash has the
     * file open, unlinking it doesn't interrupt execution, so the file can't be
     * left behind if the job dies before the private directory is removed.
     */
    private function prepareScript(SshConnection $connection, string $directory, string $script, Site $site, string $releasePath): string
    {
        $scriptPath = "{$directory}/deploy.sh";

        $contents = "#!/bin/bash\nset -e\nrm -f ".$this->escapeForShell($scriptPath)."\n\n".$this->buildScript($script, $site, $releasePath);

        $connection->upload($contents, $scriptPath);

        return $scriptPath;
    }

    /**
     * Substitute the placeholder variables and wrap the editable script in
     * the preamble and the deployment strategy's fragments.
     */
    private function buildScript(string $script, Site $site, string $releasePath): string
    {
        $phpVersion = $site->php_version ?? '8.4';

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

        return $this->scriptPreamble($site, $releasePath).$strategy->prepend($site).$userScript.$strategy->append($site);
    }

    /**
     * Get default deploy script for site if none exists.
     */
    private function getDefaultScript($site): string
    {
        return $site->project_type->defaultDeployScript();
    }
}
