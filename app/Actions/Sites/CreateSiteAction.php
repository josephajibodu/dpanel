<?php

namespace App\Actions\Sites;

use App\Data\SiteData;
use App\Enums\SiteDomainType;
use App\Enums\SiteStatus;
use App\Enums\WwwRedirect;
use App\Jobs\CreateSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Support\Str;

class CreateSiteAction
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
    ) {}

    public function execute(Server $server, SiteData $data): Site
    {
        $domain = $data->domain;
        $isFreeDomain = ! $domain && $data->siteName;
        $cloudflareRecordId = null;

        if ($isFreeDomain) {
            $freeDomain = config('server.free_domain');
            $domain = "{$data->siteName}.{$freeDomain}";
        }

        if ($isFreeDomain && $server->ip_address) {
            $cloudflareRecordId = $this->cloudflare->createARecord($domain, $server->ip_address);
        }

        $site = $server->sites()->create([
            'domain' => $domain,
            'root_path' => $domain,
            'site_name' => $data->siteName,
            'aliases' => $data->aliases,
            'directory' => $data->directory,
            'repository' => $data->repository,
            'source_control_account_id' => $data->sourceControlAccountId,
            'repository_provider' => $data->repositoryProvider->value,
            'branch' => $data->branch,
            'project_type' => $data->projectType->value,
            'server_database_id' => $data->serverDatabaseId,
            'php_version' => $data->phpVersion,
            'package_manager' => $data->packageManager,
            'build_command' => $data->buildCommand,
            'status' => SiteStatus::Pending,
            'webhook_secret' => Str::random(32),
            'auto_deploy' => $data->autoDeploy,
        ]);

        $freeDomain = config('server.free_domain');
        $isSystemDomain = str_ends_with(strtolower($domain), strtolower('.'.$freeDomain));

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => $domain,
            'type' => $isSystemDomain ? SiteDomainType::System : SiteDomainType::Custom,
            'is_primary' => true,
            'wildcard_enabled' => false,
            'www_redirect' => WwwRedirect::None,
            'is_enabled' => true,
            'cloudflare_dns_record_id' => $cloudflareRecordId,
        ]);

        foreach ($data->aliases ?? [] as $alias) {
            if (! is_string($alias) || $alias === '' || $alias === $domain) {
                continue;
            }
            SiteDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => $alias,
                'type' => str_ends_with(strtolower($alias), strtolower('.'.$freeDomain))
                    ? SiteDomainType::System
                    : SiteDomainType::Custom,
                'is_primary' => false,
                'wildcard_enabled' => false,
                'www_redirect' => WwwRedirect::None,
                'is_enabled' => true,
                'cloudflare_dns_record_id' => null,
            ]);
        }

        $site->deployScript()->create([
            'script' => $data->projectType->defaultDeployScript(),
        ]);

        CreateSiteJob::dispatch($site);

        return $site;
    }
}
