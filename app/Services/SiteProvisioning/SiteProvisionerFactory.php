<?php

namespace App\Services\SiteProvisioning;

use App\Enums\ProjectType;
use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\SourceControlService;
use App\Services\Ssh\SshConnection;

class SiteProvisionerFactory
{
    public static function make(
        ProjectType $projectType,
        SshConnection $connection,
        Site $site,
        SiteNginxSyncService $siteNginxSyncService,
        SourceControlService $sourceControlService,
    ): BaseSiteProvisioner {
        $class = match ($projectType) {
            ProjectType::Laravel => LaravelSiteProvisioner::class,
            ProjectType::Symfony => SymfonySiteProvisioner::class,
            ProjectType::PhpGeneric => PhpSiteProvisioner::class,
            ProjectType::StaticHtml => StaticHtmlSiteProvisioner::class,
            ProjectType::WordPress => WordPressSiteProvisioner::class,
        };

        return new $class($connection, $site, $siteNginxSyncService, $sourceControlService);
    }
}
