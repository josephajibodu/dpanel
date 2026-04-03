<?php

namespace App\Services\SiteProvisioning;

use App\Enums\SiteProvisioningStep;

class StaticHtmlSiteProvisioner extends BaseSiteProvisioner
{
    public function steps(): array
    {
        return SiteProvisioningStep::enumCasesForProjectType($this->site->project_type);
    }
}
