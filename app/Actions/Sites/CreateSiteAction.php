<?php

namespace App\Actions\Sites;

use App\Data\SiteData;
use App\Enums\SiteStatus;
use App\Jobs\CreateSiteJob;
use App\Models\Server;
use App\Models\Site;
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
            'cloudflare_dns_record_id' => $cloudflareRecordId,
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

        $site->deployScript()->create([
            'script' => $data->projectType->defaultDeployScript(),
        ]);

        CreateSiteJob::dispatch($site);

        return $site;
    }
}
