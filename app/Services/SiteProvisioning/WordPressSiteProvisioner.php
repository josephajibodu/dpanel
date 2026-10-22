<?php

namespace App\Services\SiteProvisioning;

use App\Enums\SiteProvisioningStep;

class WordPressSiteProvisioner extends BaseSiteProvisioner
{
    public function steps(): array
    {
        return SiteProvisioningStep::enumCasesForProjectType($this->site->project_type);
    }

    protected function createEnvironmentFile(): void
    {
        $sharedPath = $this->site->sharedPath();

        // Real wp-config.php doesn't exist yet at provisioning time (no code
        // cloned yet — see createBootstrapRelease()); each release symlinks
        // this shared file in once the real WordPress install is deployed.
        $this->runAsOwner("touch {$sharedPath}/wp-config.php && {$this->sudo}chown {$this->owner}:{$this->group} {$sharedPath}/wp-config.php && {$this->sudo}chmod {$this->secretFileMode} {$sharedPath}/wp-config.php");
        $this->runAsOwner("mkdir -p {$sharedPath}/wp-content/uploads");
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $sharedPath = $this->site->sharedPath();

        $this->runAsOwner("{$this->sudo}chmod -R 775 {$sharedPath}/wp-content/uploads");
        $this->runAsOwner("{$this->sudo}chown -R {$this->owner}:{$this->group} {$sharedPath}/wp-content");
    }
}
