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
        $this->connection->exec("if [ -f {$this->siteRoot}/wp-config-sample.php ] && [ ! -f {$this->siteRoot}/wp-config.php ]; then cp {$this->siteRoot}/wp-config-sample.php {$this->siteRoot}/wp-config.php && sudo chown {$this->serverUser}:{$this->webUser} {$this->siteRoot}/wp-config.php && sudo chmod 640 {$this->siteRoot}/wp-config.php; fi");
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $this->connection->exec("if [ -d {$this->siteRoot}/wp-content/uploads ]; then sudo chmod -R 775 {$this->siteRoot}/wp-content/uploads; fi");
        $this->connection->exec("if [ -d {$this->siteRoot}/wp-content ]; then sudo chown -R {$this->serverUser}:{$this->webUser} {$this->siteRoot}/wp-content; fi");
    }
}
