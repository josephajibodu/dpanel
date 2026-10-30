<?php

namespace App\Data;

use App\Enums\SiteDomainType;
use App\Models\Site;
use App\Services\Nginx\NginxConfigService;

/**
 * Everything a site puts on its server, worked out up front from the site
 * and its domains. Plain values only, so a queued job can still clean up
 * after the site row is gone.
 */
readonly class SiteServerFootprint
{
    /**
     * @param  array<int, string>  $webServerConfigBasenames
     * @param  array<int, string>  $snippetBasePaths
     * @param  array<int, int>  $domainIds
     * @param  array<int, string>  $customHostnames
     */
    public function __construct(
        public int $siteId,
        public string $siteUlid,
        public string $domain,
        public string $siteRoot,
        public ?string $systemUser,
        public string $phpVersion,
        public array $webServerConfigBasenames,
        public array $snippetBasePaths,
        public array $domainIds,
        public array $customHostnames,
    ) {}

    public static function fromSite(Site $site): self
    {
        $site->loadMissing('domains');

        return new self(
            siteId: $site->id,
            siteUlid: $site->ulid,
            domain: $site->domain,
            siteRoot: $site->rootPath(),
            systemUser: $site->system_user,
            phpVersion: $site->php_version ?? '8.4',
            webServerConfigBasenames: $site->domains
                ->map(fn ($d) => NginxConfigService::configFileName($site, $d))
                ->values()
                ->all(),
            snippetBasePaths: $site->domains
                ->map(fn ($d) => $site->server->webServer()->snippetsBasePath($site->ulid, $d->hostname))
                ->values()
                ->all(),
            domainIds: $site->domains
                ->pluck('id')
                ->values()
                ->all(),
            customHostnames: $site->domains
                ->filter(fn ($d) => $d->type === SiteDomainType::Custom)
                ->pluck('hostname')
                ->values()
                ->all(),
        );
    }
}
