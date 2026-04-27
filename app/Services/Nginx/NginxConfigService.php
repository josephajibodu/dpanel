<?php

namespace App\Services\Nginx;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Nginx\ConfigGenerators\NginxConfigGeneratorFactory;

class NginxConfigService
{
    /**
     * Stable filename per site + hostname (one config file per domain row).
     */
    public static function configFileName(Site $site, SiteDomain $domain): string
    {
        $normalized = str_replace(['.', '*'], ['-', 'wildcard'], $domain->hostname);

        return "{$site->ulid}--{$normalized}.conf";
    }

    public static function configPath(Site $site, SiteDomain $domain): string
    {
        return '/etc/nginx/sites-available/'.self::configFileName($site, $domain);
    }

    public static function enabledPath(Site $site, SiteDomain $domain): string
    {
        return '/etc/nginx/sites-enabled/'.self::configFileName($site, $domain);
    }

    /**
     * Generate the Nginx configuration for one site domain (single server_name group per file).
     */
    public function generateForSiteDomain(Site $site, SiteDomain $domain): string
    {
        $generator = NginxConfigGeneratorFactory::make($site);

        return $generator->generateForSiteDomain($site, $domain, false);
    }

    /**
     * Generate SSL-enabled Nginx configuration for one site domain.
     */
    public function generateWithSslForSiteDomain(Site $site, SiteDomain $domain): string
    {
        $generator = NginxConfigGeneratorFactory::make($site);

        return $generator->generateForSiteDomain($site, $domain, true);
    }
}
