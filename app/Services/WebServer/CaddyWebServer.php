<?php

namespace App\Services\WebServer;

use App\Enums\ServiceType;
use App\Exceptions\SshCommandException;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Caddy\CaddyConfigGenerator;
use App\Services\Nginx\NginxConfigService;
use App\Services\Provisioning\CaddyService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use RuntimeException;

class CaddyWebServer extends BaseWebServer
{
    public function __construct(
        NginxConfigService $siteConfig,
        SshService $sshService,
        private CaddyConfigGenerator $generator,
    ) {
        parent::__construct($siteConfig, $sshService);
    }

    public function serviceType(): ServiceType
    {
        return ServiceType::Caddy;
    }

    public function label(): string
    {
        return 'Caddy';
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

    protected function generateDomainConfig(Site $site, SiteDomain $domain): string
    {
        $ssl = $this->siteConfig->shouldUseSsl($domain);

        // Free-domain hostnames are served with the wildcard certificate
        // flitops installs; Caddy obtains certificates for custom domains itself.
        $certificateDirectory = $ssl && $this->isFreeDomain($domain)
            ? $this->certificateDirectory($site->id, $domain->id)
            : null;

        return $this->generator->generate($site, $domain, $ssl, $certificateDirectory);
    }

    private function isFreeDomain(SiteDomain $domain): bool
    {
        $freeDomain = (string) config('server.free_domain');

        return $freeDomain !== '' && str_ends_with($domain->hostname, '.'.$freeDomain);
    }
}
