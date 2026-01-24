<?php

namespace App\Actions\Sites;

use App\Data\SiteData;
use App\Enums\SiteStatus;
use App\Jobs\CreateSiteJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Str;

class CreateSiteAction
{
    public function execute(Server $server, SiteData $data): Site
    {
        // Generate domain from site_name if domain is not provided
        $domain = $data->domain;
        if (! $domain && $data->siteName && $server->ip_address) {
            // Use nip.io format: site-name.{server-ip}.nip.io
            // Convert IP from 146.190.253.93 to 146-190-253-93
            $serverIp = str_replace('.', '-', $server->ip_address);
            $domain = "{$data->siteName}.{$serverIp}.nip.io";
        }

        $site = $server->sites()->create([
            'domain' => $domain,
            'site_name' => $data->siteName,
            'aliases' => $data->aliases,
            'directory' => $data->directory,
            'repository' => $data->repository,
            'source_control_account_id' => $data->sourceControlAccountId,
            'repository_provider' => $data->repositoryProvider->value,
            'branch' => $data->branch,
            'project_type' => $data->projectType->value,
            'php_version' => $data->phpVersion,
            'package_manager' => $data->packageManager,
            'build_command' => $data->buildCommand,
            'status' => SiteStatus::Pending,
            'webhook_secret' => Str::random(32),
            'auto_deploy' => $data->autoDeploy,
        ]);

        // Create default deploy script based on project type
        $site->deployScript()->create([
            'script' => $data->projectType->defaultDeployScript(),
        ]);

        // Dispatch job to set up the site on the server
        CreateSiteJob::dispatch($site);

        return $site;
    }
}
