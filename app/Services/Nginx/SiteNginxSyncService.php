<?php

namespace App\Services\Nginx;

use App\Enums\NginxFileSection;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;

class SiteNginxSyncService
{
    public function __construct(
        private NginxConfigService $nginxConfig,
        private SshService $sshService,
    ) {}

    /**
     * Write nginx config for each enabled domain (new domains only) and reload.
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

            $this->ensureSnippetDirectories($connection, $domain);
            $this->provisionDomainConfig($connection, $site, $domain, $basename);
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
     * Provision the nginx config for a single domain.
     * Skips main config generation if the domain already has a DB record (preserves user edits).
     * Always (re)writes the ssl_redirect snippet since that is auto-managed.
     */
    protected function provisionDomainConfig(SshConnection $connection, Site $site, SiteDomain $domain, string $basename): void
    {
        $usesSsl = $this->nginxConfig->shouldUseSsl($domain);

        $existingMainFile = $site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->where('path', $basename)
            ->first();

        if ($existingMainFile) {
            // Main config already exists — preserve it, just ensure the symlink is live.
            $configPath = NginxConfigService::configPath($site, $domain);
            $enabledPath = NginxConfigService::enabledPath($site, $domain);
            $escapedContent = str_replace("'", "'\\''", $existingMainFile->content ?? '');
            $connection->exec("echo '{$escapedContent}' | sudo tee {$configPath} > /dev/null");
            $connection->exec("sudo ln -sf {$configPath} {$enabledPath}");
        } else {
            // New domain — generate and write a fresh config.
            $nginxConfig = $usesSsl
                ? $this->nginxConfig->generateWithSslForSiteDomain($site, $domain)
                : $this->nginxConfig->generateForSiteDomain($site, $domain);

            $configPath = NginxConfigService::configPath($site, $domain);
            $enabledPath = NginxConfigService::enabledPath($site, $domain);

            $escapedConfig = str_replace("'", "'\\''", $nginxConfig);
            $connection->exec("echo '{$escapedConfig}' | sudo tee {$configPath}");
            $connection->exec("sudo ln -sf {$configPath} {$enabledPath}");

            $site->nginxFiles()->create([
                'site_domain_id' => $domain->id,
                'section' => NginxFileSection::Main->value,
                'path' => $basename,
                'content' => $nginxConfig,
                'hash' => md5($nginxConfig),
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'sync_error' => null,
            ]);
        }

        $this->syncSslRedirectSnippet($connection, $site, $domain, $usesSsl);
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

    protected function ensureSnippetDirectories(SshConnection $connection, SiteDomain $domain): void
    {
        $base = $domain->nginxSnippetsBasePath();
        $connection->exec("sudo mkdir -p {$base}/before {$base}/server {$base}/locations {$base}/after");
    }

    /**
     * Write or remove before/ssl_redirect.conf for a specific domain.
     */
    protected function syncSslRedirectSnippet(SshConnection $connection, Site $site, SiteDomain $domain, bool $usesSsl): void
    {
        $dbPath = 'before/ssl_redirect.conf';

        if (! $usesSsl) {
            $snippetAbsPath = $domain->nginxSnippetsBasePath().'/before/ssl_redirect.conf';
            $connection->exec("sudo rm -f {$snippetAbsPath}");
            $site->nginxFiles()
                ->where('site_domain_id', $domain->id)
                ->where('section', NginxFileSection::Before->value)
                ->where('path', $dbPath)
                ->delete();

            return;
        }

        $content = $this->buildSslRedirectBlock($domain)."\n";
        $snippetAbsPath = $domain->nginxSnippetsBasePath().'/before/ssl_redirect.conf';
        $escaped = str_replace("'", "'\\''", $content);
        $connection->exec("echo '{$escaped}' | sudo tee {$snippetAbsPath} > /dev/null");

        $site->nginxFiles()->updateOrCreate(
            ['site_domain_id' => $domain->id, 'section' => NginxFileSection::Before->value, 'path' => $dbPath],
            [
                'content' => $content,
                'hash' => md5($content),
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'sync_error' => null,
            ]
        );
    }

    protected function buildSslRedirectBlock(SiteDomain $domain): string
    {
        $hostname = $domain->hostname;

        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_tokens off;

    server_name {$hostname};
    return 301 https://\$host\$request_uri;
}
NGINX;
    }

    /**
     * @param  array<int, string>  $expectedBasenames
     */
    protected function removeOrphanConfigs(SshConnection $connection, Site $site, array $expectedBasenames): void
    {
        $orphans = $site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->whereNotIn('path', $expectedBasenames)
            ->get();

        foreach ($orphans as $orphan) {
            $connection->exec("sudo rm -f /etc/nginx/sites-enabled/{$orphan->path}");
            $connection->exec("sudo rm -f /etc/nginx/sites-available/{$orphan->path}");
        }

        $site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->whereNotIn('path', $expectedBasenames)
            ->delete();
    }
}
