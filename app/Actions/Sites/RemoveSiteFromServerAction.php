<?php

namespace App\Actions\Sites;

use App\Data\SiteServerFootprint;
use App\Jobs\IssueSslForDomainJob;
use App\Models\Server;
use App\Services\SiteProvisioning\SiteIsolationService;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Log;

/**
 * Removes everything a site put on its server: web server configs, snippets
 * and certificates, the site's PHP-FPM pool and Linux user (isolated sites)
 * and its files. Leaves the site row alone, so it serves both deleting a site
 * and cleaning up before a failed creation is retried.
 */
class RemoveSiteFromServerAction
{
    public function __construct(
        private SiteIsolationService $isolation,
    ) {}

    public function execute(SshConnection $connection, Server $server, SiteServerFootprint $footprint): void
    {
        $webServer = $server->webServer();

        $webServer->withConfigLock($server, function () use ($connection, $webServer, $footprint): void {
            foreach ($footprint->webServerConfigBasenames as $basename) {
                foreach (array_reverse($webServer->siteConfigPaths($basename)) as $configPath) {
                    $connection->exec("sudo rm -f {$configPath}");
                }
            }

            foreach ($footprint->snippetBasePaths as $snippetsBasePath) {
                $connection->exec('sudo rm -rf '.escapeshellarg($snippetsBasePath));
            }

            foreach ($footprint->domainIds as $domainId) {
                $certDir = $webServer->certificateDirectory($footprint->siteId, $domainId);
                $connection->exec("sudo rm -rf {$certDir}");
                $connection->exec('sudo rm -f '.IssueSslForDomainJob::deployHookPath($domainId));
            }

            foreach ($footprint->customHostnames as $hostname) {
                $connection->exec('sudo certbot delete --non-interactive --cert-name '.escapeshellarg($hostname).' 2>&1 || true');
            }

            $webServer->reload($connection);
        });

        // After the reload, so the web server has stopped writing to it.
        // Rotated copies (.1, .2.gz, …) go too.
        $errorLogPath = $webServer->errorLogPath($footprint->siteUlid);
        if ($errorLogPath !== null) {
            $connection->exec("sudo rm -f {$errorLogPath} {$errorLogPath}.*");
        }

        // An isolated site's files live in its user's home, so deleting the
        // user (after its PHP-FPM pool, which would otherwise stop PHP-FPM
        // from starting) removes them too.
        if ($footprint->systemUser !== null) {
            $this->isolation->removePool($connection, $footprint->systemUser, $footprint->phpVersion);
            $this->isolation->deleteUser($connection, $footprint->systemUser, $footprint->siteUlid);
            Log::info("Removed site user {$footprint->systemUser} and its home directory");
        }

        $serverUser = config('server.user');
        if ($footprint->systemUser === null && $footprint->siteRoot && str_starts_with($footprint->siteRoot, "/home/{$serverUser}/")) {
            $connection->exec("rm -rf {$footprint->siteRoot}");
            Log::info("Removed site directory: {$footprint->siteRoot}");
        }
    }
}
