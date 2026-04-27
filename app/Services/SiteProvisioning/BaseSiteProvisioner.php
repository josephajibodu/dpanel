<?php

namespace App\Services\SiteProvisioning;

use App\Enums\SiteProvisioningStep;
use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\SourceControlService;
use App\Services\Ssh\SshConnection;
use Closure;

abstract class BaseSiteProvisioner
{
    protected string $serverUser;

    protected string $webUser;

    protected string $siteRoot;

    public function __construct(
        protected SshConnection $connection,
        protected Site $site,
        protected SiteNginxSyncService $siteNginxSyncService,
        protected SourceControlService $sourceControlService,
    ) {
        $this->serverUser = config('server.user');
        $this->webUser = config('server.web_user');
        $this->siteRoot = $site->rootPath();
    }

    /**
     * @return array<SiteProvisioningStep>
     */
    abstract public function steps(): array;

    /**
     * Run all provisioning steps, calling the onStep callback before each.
     *
     * @param  Closure(SiteProvisioningStep): void  $onStep
     */
    public function provision(Closure $onStep): void
    {
        foreach ($this->steps() as $step) {
            $onStep($step);
            $this->runStep($step);
        }
    }

    protected function runStep(SiteProvisioningStep $step): void
    {
        match ($step) {
            SiteProvisioningStep::Initializing => $this->initializeSiteDirectory(),
            SiteProvisioningStep::ConfiguringNginx => $this->configureNginx(),
            SiteProvisioningStep::CloningRepository => $this->cloneOrPlaceholder(),
            SiteProvisioningStep::CreatingEnvironmentFile => $this->createEnvironmentFile(),
            SiteProvisioningStep::InstallingDependencies => $this->installDependencies(),
            SiteProvisioningStep::BuildingFrontendAssets => $this->buildAssets(),
            SiteProvisioningStep::RunningDatabaseMigrations => $this->runMigrations(),
            SiteProvisioningStep::MakingFinalTouches => $this->setPermissions(),
        };
    }

    // ------------------------------------------------------------------
    // Shared step implementations
    // ------------------------------------------------------------------

    protected function initializeSiteDirectory(): void
    {
        $parentDir = dirname($this->siteRoot);

        $this->connection->exec("sudo rm -rf {$this->siteRoot}");
        $this->connection->exec("mkdir -p {$this->siteRoot}");
        $this->connection->exec("sudo chmod 755 {$parentDir}");
        $this->connection->exec("sudo chown -R {$this->serverUser}:{$this->serverUser} {$this->siteRoot}");
    }

    protected function configureNginx(): void
    {
        $this->site->loadMissing('domains');
        $this->siteNginxSyncService->sync($this->site, $this->connection);
    }

    protected function cloneOrPlaceholder(): void
    {
        if ($this->site->repository && $this->site->sourceControlAccount) {
            $this->sourceControlService->ensureAccountSshKey($this->site->server, $this->site->sourceControlAccount);
            $this->configureSshForGit();
            $this->cloneRepository();
        } else {
            $this->createPlaceholder();
        }
    }

    /**
     * No-op by default. Override in project-type provisioners that need it.
     */
    protected function createEnvironmentFile(): void {}

    /**
     * No-op by default. Override in project-type provisioners that need it.
     */
    protected function installDependencies(): void {}

    /**
     * No-op by default. Override in project-type provisioners that need it.
     */
    protected function buildAssets(): void {}

    /**
     * No-op by default. Override in project-type provisioners that need it.
     */
    protected function runMigrations(): void {}

    /**
     * Base permission model: serverUser:webUser ownership, 755 dirs, 644 files.
     */
    protected function setPermissions(): void
    {
        $this->connection->exec("sudo chown -R {$this->serverUser}:{$this->webUser} {$this->siteRoot}", timeout: 120);
        $this->connection->exec("sudo find {$this->siteRoot} -type d -exec chmod 755 {} \\;", timeout: 120);
        $this->connection->exec("sudo find {$this->siteRoot} -type f -exec chmod 644 {} \\;", timeout: 120);
    }

    // ------------------------------------------------------------------
    // Git helpers
    // ------------------------------------------------------------------

    protected function configureSshForGit(): void
    {
        $sshDir = "/home/{$this->serverUser}/.ssh";

        $this->connection->exec("mkdir -p {$sshDir}");
        $this->connection->exec("chmod 700 {$sshDir}");

        $knownHostsFile = "{$sshDir}/known_hosts";
        $hasHost = trim($this->connection->exec("grep -q '^github.com' {$knownHostsFile} 2>/dev/null && echo 'yes' || echo 'no'"));

        if ($hasHost === 'no') {
            $this->connection->exec("ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> {$knownHostsFile} 2>/dev/null || true");
        }

        $this->connection->exec("chmod 600 {$sshDir}/known_hosts 2>/dev/null || true");
        $this->connection->exec("chown -R {$this->serverUser}:{$this->serverUser} {$sshDir}");
    }

    protected function cloneRepository(): void
    {
        $repoUrl = $this->buildGitUrl();
        $sshKeyPath = "/home/{$this->serverUser}/.ssh/id_ed25519";

        $gitCommand = "GIT_SSH_COMMAND='ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new' git clone --branch {$this->site->branch} {$repoUrl} .";
        $this->connection->exec("cd {$this->siteRoot} && {$gitCommand}", timeout: 120);

        $this->connection->exec("git -C {$this->siteRoot} config --global --add safe.directory {$this->siteRoot}");
    }

    protected function buildGitUrl(): string
    {
        $baseUrl = $this->site->repository_provider?->baseUrl();

        if (! $baseUrl) {
            return $this->site->repository;
        }

        return match ($this->site->repository_provider?->value) {
            'github' => "git@github.com:{$this->site->repository}.git",
            'gitlab' => "git@gitlab.com:{$this->site->repository}.git",
            'bitbucket' => "git@bitbucket.org:{$this->site->repository}.git",
            default => $this->site->repository,
        };
    }

    protected function createPlaceholder(): void
    {
        $webDir = ltrim($this->site->directory ?: '/', '/');

        if ($webDir && $webDir !== '/') {
            $this->connection->exec("mkdir -p {$this->siteRoot}/{$webDir}");
        }

        $placeholderPath = $webDir ? "{$this->siteRoot}/{$webDir}/index.php" : "{$this->siteRoot}/index.php";
        $appName = addslashes((string) config('app.name'));
        $placeholder = <<<PHP
<?php
echo '<h1>Site coming soon!</h1>';
echo "This site is hosted by {$appName}.";
PHP;

        $escapedPlaceholder = str_replace("'", "'\\''", $placeholder);
        $this->connection->exec("echo '{$escapedPlaceholder}' > {$placeholderPath}");
    }

    // ------------------------------------------------------------------
    // PHP helpers
    // ------------------------------------------------------------------

    protected function phpBinary(): string
    {
        $phpVersion = $this->site->php_version ?? '8.4';

        return "php{$phpVersion}";
    }
}
