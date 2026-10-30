<?php

namespace App\Services\WebServer;

use App\Contracts\WebServerContract;
use App\Enums\NginxFileSection;
use App\Enums\ServiceType;
use App\Exceptions\SshCommandException;
use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\Ssh\SshConnection;
use RuntimeException;

class NginxWebServer implements WebServerContract
{
    public function __construct(
        private SiteNginxSyncService $siteSync,
    ) {}

    public function serviceType(): ServiceType
    {
        return ServiceType::Nginx;
    }

    public function label(): string
    {
        return 'Nginx';
    }

    public function syncSite(Site $site, ?SshConnection $connection = null): void
    {
        $this->siteSync->sync($site, $connection);
    }

    public function validate(SshConnection $connection): void
    {
        try {
            $connection->exec('sudo nginx -t 2>&1');
        } catch (SshCommandException $exception) {
            throw new RuntimeException('Nginx validation failed: '.$exception->output);
        }
    }

    public function reload(SshConnection $connection): void
    {
        $connection->exec('sudo systemctl reload nginx');
    }

    public function validateAndReload(SshConnection $connection): void
    {
        // Both halves of `&&` must run under sudo. `&&` is parsed by the
        // outer shell, so a single `sudo` prefix only applies to the first
        // command; the second would otherwise run as the SSH user, which
        // polkit rejects with "Interactive authentication required".
        $connection->exec('sudo nginx -t && sudo systemctl reload nginx');
    }

    public function siteConfigPaths(string $configFileName): array
    {
        return [
            "/etc/nginx/sites-available/{$configFileName}",
            "/etc/nginx/sites-enabled/{$configFileName}",
        ];
    }

    public function snippetsBasePath(string $siteUlid, string $hostname): string
    {
        return "/etc/nginx/flitops-conf/{$siteUlid}/{$hostname}";
    }

    public function certificateDirectory(int $siteId, int $domainId): string
    {
        return "/etc/nginx/ssl/domains/{$siteId}/{$domainId}";
    }

    public function certificateGroup(): string
    {
        return 'root';
    }

    public function obtainsCertificates(): bool
    {
        return false;
    }

    public function fileSections(): array
    {
        return NginxFileSection::cases();
    }
}
