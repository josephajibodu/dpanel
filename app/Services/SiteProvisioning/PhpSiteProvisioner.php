<?php

namespace App\Services\SiteProvisioning;

use App\Enums\SiteProvisioningStep;

class PhpSiteProvisioner extends BaseSiteProvisioner
{
    public function steps(): array
    {
        return SiteProvisioningStep::enumCasesForProjectType($this->site->project_type);
    }

    protected function createEnvironmentFile(): void
    {
        $sharedPath = $this->site->sharedPath();

        $this->runAsOwner("touch {$sharedPath}/.env && {$this->sudo}chown {$this->owner}:{$this->group} {$sharedPath}/.env && {$this->sudo}chmod {$this->secretFileMode} {$sharedPath}/.env");
    }
}
