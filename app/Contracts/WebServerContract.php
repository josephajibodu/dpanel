<?php

namespace App\Contracts;

use App\Enums\NginxFileSection;
use App\Enums\ServiceType;
use App\Models\Site;
use App\Services\Ssh\SshConnection;

/**
 * The web server a server runs (Nginx or Caddy). Everything that writes site
 * configs, checks them or reloads the web server goes through this, so the
 * rest of the app doesn't need to know which one is installed.
 */
interface WebServerContract
{
    public function serviceType(): ServiceType;

    public function label(): string;

    /**
     * Write the site's per-domain configs, remove ones for domains it no
     * longer has, then check the config and reload.
     *
     * @param  SshConnection|null  $connection  When null, a connection is opened and closed. When provided, it is not closed.
     */
    public function syncSite(Site $site, ?SshConnection $connection = null): void;

    /**
     * Check the whole config. Throws a RuntimeException naming the problem when it's invalid.
     */
    public function validate(SshConnection $connection): void;

    public function reload(SshConnection $connection): void;

    /**
     * Check the config and reload only if it's valid.
     */
    public function validateAndReload(SshConnection $connection): void;

    /**
     * Every path a domain's main config lives at on the server. The first is
     * the file itself; any others are links to it that must be removed with it.
     *
     * @return array<int, string>
     */
    public function siteConfigPaths(string $configFileName): array;

    /**
     * Folder holding the snippet files users add for one domain of a site.
     */
    public function snippetsBasePath(string $siteUlid, string $hostname): string;

    /**
     * File the web server writes one site's errors to, or null when it
     * doesn't keep a log per site. It's outside the site's own folders so
     * an isolated site's user can't replace it with a link to another file.
     */
    public function errorLogPath(string $siteUlid): ?string;

    /**
     * Folder holding a domain's certificate and key when flitops installs them
     * (custom-domain certificates from certbot, and the free-domain wildcard).
     */
    public function certificateDirectory(int $siteId, int $domainId): string;

    /**
     * Group that must be able to read installed private keys.
     */
    public function certificateGroup(): string;

    /**
     * Whether the web server obtains and renews custom-domain certificates
     * itself, so flitops doesn't run certbot for them.
     */
    public function obtainsCertificates(): bool;

    /**
     * The config editor sections this web server supports.
     *
     * @return array<int, NginxFileSection>
     */
    public function fileSections(): array;
}
