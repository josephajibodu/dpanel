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
        $this->connection->exec("if [ -f {$this->siteRoot}/.env.example ]; then cp {$this->siteRoot}/.env.example {$this->siteRoot}/.env && sudo chown {$this->serverUser}:{$this->webUser} {$this->siteRoot}/.env && sudo chmod 640 {$this->siteRoot}/.env; fi");
    }

    protected function installDependencies(): void
    {
        $this->connection->exec(
            "if [ -f {$this->siteRoot}/composer.json ]; then cd {$this->siteRoot} && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi",
            timeout: 300,
        );
    }
}
