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
        $this->connection->exec("touch {$sharedPath}/wp-config.php && sudo chown {$this->serverUser}:{$this->webUser} {$sharedPath}/wp-config.php && sudo chmod 640 {$sharedPath}/wp-config.php");
        $this->connection->exec("mkdir -p {$sharedPath}/wp-content/uploads");
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $sharedPath = $this->site->sharedPath();

        $this->connection->exec("sudo chmod -R 775 {$sharedPath}/wp-content/uploads");
        $this->connection->exec("sudo chown -R {$this->serverUser}:{$this->webUser} {$sharedPath}/wp-content");
    }
}
