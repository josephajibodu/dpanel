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
        $site = $server->sites()->create([
            'domain' => $data->domain,
            'aliases' => $data->aliases,
            'directory' => $data->directory,
            'repository' => $data->repository,
            'repository_provider' => $data->repositoryProvider->value,
            'branch' => $data->branch,
            'project_type' => $data->projectType->value,
            'php_version' => $data->phpVersion,
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
