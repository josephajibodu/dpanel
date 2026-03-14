<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Events\ServerSitesUpdated;
use App\Models\Site;
use App\Services\Nginx\NginxConfigService;
use App\Services\SourceControlService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Illuminate\Support\Facades\Log;

class ProvisionSiteAction
{
    public function __construct(
        private SshService $sshService,
        private NginxConfigService $nginxService,
        private SourceControlService $sourceControlService,
    ) {}

    public function execute(Site $site): void
    {
        $server = $site->server;

        Log::info("Creating site {$site->domain} on server {$server->name}");

        try {
            $site->update(['status' => SiteStatus::Installing]);
            $this->broadcastServerSitesUpdated($server);

            $connection = $this->sshService->connect($server);

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
            $nginxConfig = $this->nginxService->generate($site);
            $configPath = $this->nginxService->configPath($site);
            $enabledPath = $this->nginxService->enabledPath($site);

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
                $this->sourceControlService->ensureAccountSshKey($server, $site->sourceControlAccount);
                $this->configureSshForGit($connection, $site);
                $this->cloneRepository($connection, $site);
            } else {
                $this->createPlaceholder($connection, $site);
            }

            // Set proper permissions (matching Forge: 775 for dirs, 664 for files)
            $serverUser = config('server.user');
            $webUser = config('server.web_user');
            $connection->exec("chown -R {$serverUser}:{$serverUser} {$siteRoot}");
            $connection->exec("find {$siteRoot} -type d -exec chmod 775 {} \\;");
            $connection->exec("find {$siteRoot} -type f -exec chmod 664 {} \\;");

            // Allow web server (www-data) to write to Laravel paths (storage, bootstrap/cache, database)
            $connection->exec("if [ -d {$siteRoot}/storage ]; then sudo chown -R {$serverUser}:{$webUser} {$siteRoot}/storage {$siteRoot}/bootstrap/cache {$siteRoot}/database && sudo chmod -R 775 {$siteRoot}/storage {$siteRoot}/bootstrap/cache {$siteRoot}/database; fi");

            // Allow web server (www-data) to write to Symfony paths (var/cache, var/log)
            $connection->exec("if [ -d {$siteRoot}/var ]; then mkdir -p {$siteRoot}/var/cache {$siteRoot}/var/log && sudo chown -R {$serverUser}:{$webUser} {$siteRoot}/var/cache {$siteRoot}/var/log && sudo chmod -R 775 {$siteRoot}/var/cache {$siteRoot}/var/log; fi");

            $connection->disconnect();

            // Site setup completed, deployment remains user-triggered.
            $site->update(['status' => SiteStatus::Provisioned]);

            Log::info("Site {$site->domain} created successfully");

            $this->broadcastServerSitesUpdated($server);
        } catch (\Throwable $e) {
            Log::error("Failed to create site {$site->domain}: {$e->getMessage()}");

            $site->update(['status' => SiteStatus::Failed]);

            $this->broadcastServerSitesUpdated($server);

            throw $e;
        }
    }

    private function configureSshForGit(SshConnection $connection, Site $site): void
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

    private function cloneRepository(SshConnection $connection, Site $site): void
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

        // Git 2.35.2+ requires safe.directory when repo ownership differs from git user
        $connection->exec("git -C {$siteRoot} config --global --add safe.directory {$siteRoot}");
    }

    private function buildGitUrl(Site $site): string
    {
        $baseUrl = $site->repository_provider?->baseUrl();

        if (! $baseUrl) {
            return $site->repository;
        }

        return match ($site->repository_provider?->value) {
            'github' => "git@github.com:{$site->repository}.git",
            'gitlab' => "git@gitlab.com:{$site->repository}.git",
            'bitbucket' => "git@bitbucket.org:{$site->repository}.git",
            default => $site->repository,
        };
    }

    private function createPlaceholder(SshConnection $connection, Site $site): void
    {
        $siteRoot = $site->rootPath();
        $webDir = ltrim($site->directory ?: '/', '/');

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

    private function broadcastServerSitesUpdated($server): void
    {
        $server->load(['sites' => fn ($q) => $q->with('latestDeployment')->latest()]);
        broadcast(new ServerSitesUpdated($server));
    }
}
