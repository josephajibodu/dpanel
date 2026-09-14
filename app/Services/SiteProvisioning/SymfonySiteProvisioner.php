<?php

namespace App\Services\SiteProvisioning;

use App\Enums\SiteProvisioningStep;

class SymfonySiteProvisioner extends BaseSiteProvisioner
{
    public function steps(): array
    {
        return SiteProvisioningStep::enumCasesForProjectType($this->site->project_type);
    }

    protected function createEnvironmentFile(): void
    {
        $sharedPath = $this->site->sharedPath();

        // No real code exists yet at provisioning time (see createBootstrapRelease()),
        // so both files start empty — the app's env-var management UI (SyncEnvironmentJob)
        // fills in .env; .env.local persists whatever the app itself writes to it.
        $this->connection->exec("touch {$sharedPath}/.env {$sharedPath}/.env.local");

        $this->connection->exec("sudo chown {$this->serverUser}:{$this->webUser} {$sharedPath}/.env.local && sudo chmod 640 {$sharedPath}/.env.local");
        $this->connection->exec("sudo chown {$this->serverUser}:{$this->webUser} {$sharedPath}/.env && sudo chmod 640 {$sharedPath}/.env");
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $sharedPath = $this->site->sharedPath();

        $this->connection->exec("mkdir -p {$sharedPath}/var/cache {$sharedPath}/var/log");
        $this->connection->exec("sudo chown -R {$this->serverUser}:{$this->webUser} {$sharedPath}/var/cache {$sharedPath}/var/log");
        $this->connection->exec("sudo chmod -R 775 {$sharedPath}/var/cache {$sharedPath}/var/log");
    }
}
