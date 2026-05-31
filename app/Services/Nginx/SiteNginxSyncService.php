<?php

namespace App\Services\Nginx;

use App\Models\Site;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

class SiteNginxSyncService
{
    public function __construct(
        private NginxConfigService $nginxConfig,
        private SshService $sshService,
    ) {}

    /**
     * Write one Nginx config per enabled site domain, remove orphaned files for this site, test and reload.
     *
     * @param  SshConnection|null  $connection  When null, a connection is opened and closed. When provided, it is not closed.
     */
    public function sync(Site $site, ?SshConnection $connection = null): void
    {
        $site->loadMissing('server', 'domains');

        $shouldDisconnect = $connection === null;
        $connection ??= $this->sshService->connect($site->server);

        $enabledDomains = $site->domains()->where('is_enabled', true)->get();
        $expectedBasenames = [];

        foreach ($enabledDomains as $domain) {
            $basename = NginxConfigService::configFileName($site, $domain);
            $expectedBasenames[] = $basename;
            $nginxConfig = $this->nginxConfig->generateForSiteDomainAuto($site, $domain);
            $configPath = NginxConfigService::configPath($site, $domain);
            $enabledPath = NginxConfigService::enabledPath($site, $domain);

            $escapedConfig = str_replace("'", "'\\''", $nginxConfig);
            $connection->exec("echo '{$escapedConfig}' | sudo tee {$configPath}");
            $connection->exec("sudo ln -sf {$configPath} {$enabledPath}");
        }

        $this->removeOrphanConfigs($connection, $site, $expectedBasenames);

        $testResult = $connection->exec('sudo nginx -t 2>&1');
        if (! str_contains($testResult, 'syntax is ok')) {
            throw new \RuntimeException("Nginx configuration test failed: {$testResult}");
        }

        $connection->exec('sudo systemctl reload nginx');

        $this->synchronizeAppUrl($connection, $site);

        if ($shouldDisconnect) {
            $connection->disconnect();
        }
    }

    /**
     * Keep APP_URL in sync with the primary domain when .env exists.
     */
    protected function synchronizeAppUrl(SshConnection $connection, Site $site): void
    {
        $site->loadMissing('domains');
        $primary = $site->domains()->where('is_primary', true)->first();
        if (! $primary) {
            return;
        }

        $envPath = $site->rootPath().'/.env';
        $url = 'https://'.$primary->hostname;
        $escapedValue = str_replace(['\\', '#', '&'], ['\\\\', '\\#', '\\&'], $url);

        $connection->exec(
            "if [ -f {$envPath} ] && grep -q '^APP_URL=' {$envPath}; then "
            ."sed -i 's#^APP_URL=.*#APP_URL={$escapedValue}#' {$envPath}; "
            ."elif [ -f {$envPath} ]; then "
            ."echo 'APP_URL={$url}' >> {$envPath}; fi"
        );
    }

    /**
     * @param  array<int, string>  $expectedBasenames
     */
    protected function removeOrphanConfigs(SshConnection $connection, Site $site, array $expectedBasenames): void
    {
        $prefix = $site->ulid.'--';
        $listResult = $connection->exec('ls -1 /etc/nginx/sites-available/ 2>/dev/null || true');
        $lines = array_filter(array_map('trim', explode("\n", $listResult)));

        foreach ($lines as $file) {
            if (! str_starts_with($file, $prefix)) {
                continue;
            }
            if (! str_ends_with($file, '.conf')) {
                continue;
            }
            if (in_array($file, $expectedBasenames, true)) {
                continue;
            }
            $available = "/etc/nginx/sites-available/{$file}";
            $enabled = "/etc/nginx/sites-enabled/{$file}";
            $connection->exec("sudo rm -f {$enabled}");
            $connection->exec("sudo rm -f {$available}");
        }
    }
}
