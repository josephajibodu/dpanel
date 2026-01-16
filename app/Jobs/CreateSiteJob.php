<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\NginxConfigService;
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

    public function handle(SshService $sshService, NginxConfigService $nginxService): void
    {
        $site = $this->site;
        $server = $site->server;

        Log::info("Creating site {$site->domain} on server {$server->name}");

        try {
            $site->update(['status' => SiteStatus::Installing]);

            $connection = $sshService->connect($server);

            // Create site directory
            $siteRoot = $site->rootPath();
            $connection->exec("mkdir -p {$siteRoot}");

            // Set ownership
            $connection->exec("chown -R forge:forge {$siteRoot}");

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
            if ($site->repository) {
                $this->cloneRepository($connection, $site);
            } else {
                // Create a simple index.php as placeholder
                $this->createPlaceholder($connection, $site);
            }

            // Set proper permissions
            $connection->exec("chown -R forge:forge {$siteRoot}");
            $connection->exec("chmod -R 755 {$siteRoot}");

            $connection->disconnect();

            $site->update(['status' => SiteStatus::Deployed]);

            Log::info("Site {$site->domain} created successfully");

        } catch (\Throwable $e) {
            Log::error("Failed to create site {$site->domain}: {$e->getMessage()}");

            $site->update(['status' => SiteStatus::Failed]);

            throw $e;
        }
    }

    private function cloneRepository($connection, Site $site): void
    {
        $siteRoot = $site->rootPath();
        $repoUrl = $this->buildGitUrl($site);

        // Clone the repository
        $connection->exec("cd {$siteRoot} && git clone --branch {$site->branch} {$repoUrl} .");
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

        $placeholder = <<<'PHP'
<?php
echo '<h1>Site coming soon!</h1>';
echo '<p>This site is hosted by ServerForge.</p>';
PHP;

        $escapedPlaceholder = str_replace("'", "'\\''", $placeholder);
        $connection->exec("echo '{$escapedPlaceholder}' > {$placeholderPath}");
    }
}
