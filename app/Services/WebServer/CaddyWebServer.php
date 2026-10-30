<?php

namespace App\Services\WebServer;

use App\Contracts\WebServerContract;
use App\Enums\NginxFileSection;
use App\Enums\ServiceType;
use App\Exceptions\SshCommandException;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Caddy\CaddyConfigGenerator;
use App\Services\Nginx\NginxConfigService;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\Provisioning\CaddyService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class CaddyWebServer implements WebServerContract
{
    public function __construct(
        private CaddyConfigGenerator $generator,
        private NginxConfigService $sslDecider,
        private SiteNginxSyncService $nginxSync,
        private SshService $sshService,
    ) {}

    public function serviceType(): ServiceType
    {
        return ServiceType::Caddy;
    }

    public function label(): string
    {
        return 'Caddy';
    }

    /**
     * Main configs are always regenerated: the editor can't change them, so
     * there are no edits to keep, and regenerating picks up a new PHP
     * version, folder or certificate without patching stored files.
     */
    public function syncSite(Site $site, ?SshConnection $connection = null): void
    {
        $site->loadMissing('server', 'domains');

        $shouldDisconnect = $connection === null;
        $connection ??= $this->sshService->connect($site->server);

        try {
            $expectedBasenames = [];

            foreach ($site->domains()->where('is_enabled', true)->get() as $domain) {
                $basename = NginxConfigService::configFileName($site, $domain);
                $expectedBasenames[] = $basename;

                $this->writeDomainConfig($connection, $site, $domain, $basename);
            }

            $this->removeOrphanConfigs($connection, $site, $expectedBasenames);

            $this->validateAndReload($connection);

            $this->nginxSync->synchronizeAppUrl($connection, $site);
        } finally {
            if ($shouldDisconnect) {
                $connection->disconnect();
            }
        }
    }

    public function validate(SshConnection $connection): void
    {
        $webUser = (string) config('server.web_user');
        $dataDirectory = CaddyService::DATA_DIRECTORY;

        // Run as the web user Caddy runs as, so an unreadable certificate or
        // snippet fails here instead of on reload.
        try {
            $connection->exec(
                "sudo -u {$webUser} env XDG_DATA_HOME={$dataDirectory}/.local/share XDG_CONFIG_HOME={$dataDirectory}/.config "
                .'caddy validate --config '.CaddyService::CADDYFILE.' --adapter caddyfile 2>&1',
                60,
            );
        } catch (SshCommandException $exception) {
            throw new RuntimeException('Caddy validation failed: '.$exception->output);
        }
    }

    public function reload(SshConnection $connection): void
    {
        $connection->exec('sudo '.CaddyService::RELOAD_SCRIPT, 60);
    }

    public function validateAndReload(SshConnection $connection): void
    {
        $this->validate($connection);
        $this->reload($connection);
    }

    public function siteConfigPaths(string $configFileName): array
    {
        return [CaddyService::SITES_DIRECTORY."/{$configFileName}"];
    }

    public function snippetsBasePath(string $siteUlid, string $hostname): string
    {
        return "/etc/caddy/flitops-conf/{$siteUlid}/{$hostname}";
    }

    /**
     * Caddy logs errors for every site together in its service journal.
     */
    public function errorLogPath(string $siteUlid): ?string
    {
        return null;
    }

    public function certificateDirectory(int $siteId, int $domainId): string
    {
        return "/etc/caddy/ssl/domains/{$siteId}/{$domainId}";
    }

    public function certificateGroup(): string
    {
        return (string) config('server.web_user');
    }

    public function obtainsCertificates(): bool
    {
        return true;
    }

    public function fileSections(): array
    {
        return [NginxFileSection::Main, NginxFileSection::Server, NginxFileSection::After];
    }

    private function writeDomainConfig(SshConnection $connection, Site $site, SiteDomain $domain, string $basename): void
    {
        $snippetsBase = $domain->nginxSnippetsBasePath();
        $connection->exec("sudo mkdir -p {$snippetsBase}/server {$snippetsBase}/after");

        $ssl = $this->sslDecider->shouldUseSsl($domain);

        // Free-domain hostnames are served with the wildcard certificate
        // flitops installs; Caddy obtains certificates for custom domains itself.
        $certificateDirectory = $ssl && $this->isFreeDomain($domain)
            ? $this->certificateDirectory($site->id, $domain->id)
            : null;

        $config = $this->generator->generate($site, $domain, $ssl, $certificateDirectory);
        $configPath = $this->siteConfigPaths($basename)[0];

        $escapedConfig = str_replace("'", "'\\''", $config);
        $connection->exec("echo '{$escapedConfig}' | sudo tee {$configPath} > /dev/null");

        $site->nginxFiles()->updateOrCreate(
            ['site_domain_id' => $domain->id, 'section' => NginxFileSection::Main->value, 'path' => $basename],
            [
                'content' => $config,
                'hash' => md5($config),
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'sync_error' => null,
            ],
        );
    }

    /**
     * @param  array<int, string>  $expectedBasenames
     */
    private function removeOrphanConfigs(SshConnection $connection, Site $site, array $expectedBasenames): void
    {
        $orphans = $site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->whereNotIn('path', $expectedBasenames)
            ->get();

        foreach ($orphans as $orphan) {
            $connection->exec('sudo rm -f '.$this->siteConfigPaths($orphan->path)[0]);
            $orphan->delete();
        }
    }

    private function isFreeDomain(SiteDomain $domain): bool
    {
        $freeDomain = (string) config('server.free_domain');

        return $freeDomain !== '' && str_ends_with($domain->hostname, '.'.$freeDomain);
    }
}
