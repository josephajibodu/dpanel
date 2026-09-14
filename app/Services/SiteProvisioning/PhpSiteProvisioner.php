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

        $this->connection->exec("touch {$sharedPath}/.env && sudo chown {$this->serverUser}:{$this->webUser} {$sharedPath}/.env && sudo chmod 640 {$sharedPath}/.env");
    }
}
