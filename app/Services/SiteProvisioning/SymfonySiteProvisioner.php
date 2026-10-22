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
        $this->runAsOwner("touch {$sharedPath}/.env {$sharedPath}/.env.local");

        $this->runAsOwner("{$this->sudo}chown {$this->owner}:{$this->group} {$sharedPath}/.env.local && {$this->sudo}chmod {$this->secretFileMode} {$sharedPath}/.env.local");
        $this->runAsOwner("{$this->sudo}chown {$this->owner}:{$this->group} {$sharedPath}/.env && {$this->sudo}chmod {$this->secretFileMode} {$sharedPath}/.env");
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $sharedPath = $this->site->sharedPath();

        $this->runAsOwner("mkdir -p {$sharedPath}/var/cache {$sharedPath}/var/log");
        $this->runAsOwner("{$this->sudo}chown -R {$this->owner}:{$this->group} {$sharedPath}/var/cache {$sharedPath}/var/log");
        $this->runAsOwner("{$this->sudo}chmod -R 775 {$sharedPath}/var/cache {$sharedPath}/var/log");
    }
}
