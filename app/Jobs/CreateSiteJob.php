<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Nginx\NginxConfigService;
use App\Services\SourceControlService;
use App\Services\Ssh\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 0;

    public int $timeout = 300;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(
        SshService $sshService,
        NginxConfigService $nginxService,
        SourceControlService $sourceControlService,
    ): void {
        $site = $this->site;
        $server = $site->server;

        Log::info("Creating site {$site->domain} on server {$server->name}");

        try {
            $appName = config('app.name');
            $site->update(['status' => SiteStatus::Installing]);

            $connection = $sshService->connect($server);

            // Create site directory
            $siteRoot = $site->rootPath();
            $connection->exec("mkdir -p {$siteRoot}");

            // Ensure parent directory is accessible (like Forge does)
            $parentDir = dirname($siteRoot);
            $connection->exec("sudo chmod 755 {$parentDir}");

            // Set ownership
            $serverUser = config('server.user');
            $connection->exec("chown -R {$serverUser}:{$serverUser} {$siteRoot}");

            // Generate Nginx config
            $nginxConfig = $nginxService->generate($site);
            $configPath = $nginxService->configPath($site);
            $enabledPath = $nginxService->enabledPath($site);

            // Write Nginx config using sudo
            $escapedConfig = str_replace("'", "'\\''", $nginxConfig);
            $connection->exec("echo '{$escapedConfig}' | sudo tee {$configPath}");

            // Enable the site
            $connection->exec("sudo ln -sf {$configPath} {$enabledPath}");

            // Test Nginx config
            $testResult = $connection->exec('sudo nginx -t 2>&1');
            if (! str_contains($testResult, 'syntax is ok')) {
                throw new \RuntimeException("Nginx configuration test failed: {$testResult}");
            }

            // Reload Nginx
            $connection->exec('sudo systemctl reload nginx');

            // Clone repository if provided
            if ($site->repository && $site->sourceControlAccount) {
                // Ensure account-level SSH key exists for this server/account combination.
                $sourceControlService->ensureAccountSshKey($server, $site->sourceControlAccount);

                // Configure SSH for GitHub if needed
                $this->configureSshForGit($connection, $site);

                $this->cloneRepository($connection, $site);
            } else {
                // Create a simple index.php as placeholder
                $this->createPlaceholder($connection, $site);
            }

            // Set proper permissions (matching Forge: 775 for dirs, 664 for files)
            $serverUser = config('server.user');
            $connection->exec("chown -R {$serverUser}:{$serverUser} {$siteRoot}");
            $connection->exec("find {$siteRoot} -type d -exec chmod 775 {} \\;");
            $connection->exec("find {$siteRoot} -type f -exec chmod 664 {} \\;");

            $connection->disconnect();

            $site->update(['status' => SiteStatus::Deployed]);

            Log::info("Site {$site->domain} created successfully");

        } catch (\Throwable $e) {
            Log::error("Failed to create site {$site->domain}: {$e->getMessage()}");

            $site->update(['status' => SiteStatus::Failed]);

            throw $e;
        }
    }

    private function configureSshForGit($connection, Site $site): void
    {
        $serverUser = config('server.user');
        $sshDir = "/home/{$serverUser}/.ssh";

        // Ensure .ssh directory exists and has correct permissions
        $connection->exec("mkdir -p {$sshDir}");
        $connection->exec("chmod 700 {$sshDir}");

        // Add GitHub to known_hosts if not already present
        // Use ssh-keyscan to get the current GitHub host keys (more reliable)
        $knownHostsFile = "{$sshDir}/known_hosts";
        $hasGithub = trim($connection->exec("grep -q '^github.com' {$knownHostsFile} 2>/dev/null && echo 'yes' || echo 'no'"));

        if ($hasGithub === 'no') {
            // Use ssh-keyscan to get GitHub's host keys and append to known_hosts
            $connection->exec("ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> {$knownHostsFile} 2>/dev/null || true");
        }

        // Ensure known_hosts has correct permissions
        $connection->exec("chmod 600 {$sshDir}/known_hosts 2>/dev/null || true");
        $connection->exec("chown -R {$serverUser}:{$serverUser} {$sshDir}");
    }

    private function cloneRepository($connection, Site $site): void
    {
        $siteRoot = $site->rootPath();
        $repoUrl = $this->buildGitUrl($site);
        $serverUser = config('server.user');

        // Use GIT_SSH_COMMAND to ensure we use the server's local SSH key
        // The key should be at ~/.ssh/id_ed25519 (default location)
        $sshKeyPath = "/home/{$serverUser}/.ssh/id_ed25519";

        // Clone the repository using the server's local SSH key
        // GIT_SSH_COMMAND ensures git uses the correct SSH key
        $gitCommand = "GIT_SSH_COMMAND='ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new' git clone --branch {$site->branch} {$repoUrl} .";
        $connection->exec("cd {$siteRoot} && {$gitCommand}");
    }

    private function buildGitUrl(Site $site): string
    {
        $baseUrl = $site->repository_provider?->baseUrl();

        if (! $baseUrl) {
            // Custom repository URL provided
            return $site->repository;
        }

        // Build SSH URL for cloning (preferred for deploy keys)
        return match ($site->repository_provider?->value) {
            'github' => "git@github.com:{$site->repository}.git",
            'gitlab' => "git@gitlab.com:{$site->repository}.git",
            'bitbucket' => "git@bitbucket.org:{$site->repository}.git",
            default => $site->repository,
        };
    }

    private function createPlaceholder($connection, Site $site): void
    {
        $siteRoot = $site->rootPath();
        $webDir = ltrim($site->directory ?: '/', '/');

        // Create web directory if different from root
        if ($webDir && $webDir !== '/') {
            $connection->exec("mkdir -p {$siteRoot}/{$webDir}");
        }

        $placeholderPath = $webDir ? "{$siteRoot}/{$webDir}/index.php" : "{$siteRoot}/index.php";

        $appName = addslashes((string) config('app.name'));

        $placeholder = <<<PHP
<?php
echo '<h1>Site coming soon!</h1>';
echo "This site is hosted by {$appName}.";
PHP;

        $escapedPlaceholder = str_replace("'", "'\\''", $placeholder);
        $connection->exec("echo '{$escapedPlaceholder}' > {$placeholderPath}");
    }
}
