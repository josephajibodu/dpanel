<?php

namespace App\Services\WebServer;

use App\Enums\ServiceType;
use App\Exceptions\SshCommandException;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Ssh\SshConnection;
use RuntimeException;

class NginxWebServer extends BaseWebServer
{
    public function serviceType(): ServiceType
    {
        return ServiceType::Nginx;
    }

    public function label(): string
    {
        return 'Nginx';
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

    /**
     * Inside /var/log/nginx, so the nginx package's logrotate rule
     * (/var/log/nginx/*.log) rotates it with the server's other logs.
     */
    public function errorLogPath(string $siteUlid): ?string
    {
        return "/var/log/nginx/{$siteUlid}-error.log";
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

    protected function generateDomainConfig(Site $site, SiteDomain $domain): string
    {
        return $this->siteConfig->generateForSiteDomainAuto($site, $domain);
    }

    /**
     * Also clears what earlier versions kept beside the user's files: the
     * `before` and `locations` folders, and flitops' own redirect, error-log
     * and certificate-check files. All of that is now part of the main config.
     */
    protected function prepareDomain(SshConnection $connection, Site $site, SiteDomain $domain): void
    {
        $snippetsBase = $domain->nginxSnippetsBasePath();

        $connection->exec(
            "sudo mkdir -p {$snippetsBase}/server {$snippetsBase}/after"
            ." && sudo rm -rf {$snippetsBase}/before {$snippetsBase}/locations"
            ." {$snippetsBase}/server/acme_challenge.conf {$snippetsBase}/server/error_log.conf"
        );

        $site->nginxFiles()
            ->where('site_domain_id', $domain->id)
            ->where(fn ($query) => $query
                ->whereIn('section', ['before', 'locations'])
                ->orWhereIn('path', ['server/acme_challenge.conf', 'server/error_log.conf']))
            ->delete();
    }
}
