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
        $this->connection->exec("if [ -f {$this->siteRoot}/.env.local.php ]; then true; elif [ -f {$this->siteRoot}/.env ]; then cp {$this->siteRoot}/.env {$this->siteRoot}/.env.local; fi");

        $this->connection->exec("if [ -f {$this->siteRoot}/.env.local ]; then sudo chown {$this->serverUser}:{$this->webUser} {$this->siteRoot}/.env.local && sudo chmod 640 {$this->siteRoot}/.env.local; fi");

        $this->connection->exec("if [ -f {$this->siteRoot}/.env ]; then sudo chown {$this->serverUser}:{$this->webUser} {$this->siteRoot}/.env && sudo chmod 640 {$this->siteRoot}/.env; fi");
    }

    protected function installDependencies(): void
    {
        $this->connection->exec(
            "if [ -f {$this->siteRoot}/composer.json ]; then cd {$this->siteRoot} && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi",
            timeout: 300,
        );
    }

    protected function setPermissions(): void
    {
        parent::setPermissions();

        $this->connection->exec("mkdir -p {$this->siteRoot}/var/cache {$this->siteRoot}/var/log");
        $this->connection->exec("sudo chown -R {$this->serverUser}:{$this->webUser} {$this->siteRoot}/var/cache {$this->siteRoot}/var/log");
        $this->connection->exec("sudo chmod -R 775 {$this->siteRoot}/var/cache {$this->siteRoot}/var/log");
    }
}
