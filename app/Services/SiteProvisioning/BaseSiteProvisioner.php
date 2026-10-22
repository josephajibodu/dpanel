<?php

namespace App\Services\SiteProvisioning;

use App\Actions\Certificates\SyncWildcardCertificateForDomainAction;
use App\Enums\SiteProvisioningStep;
use App\Models\Site;
use App\Services\Nginx\SiteNginxSyncService;
use App\Services\SourceControlService;
use App\Services\Ssh\SshConnection;
use Closure;

abstract class BaseSiteProvisioner
{
    protected string $serverUser;

    /**
     * The Linux user that owns the site's files: the site's own user when
     * isolated, otherwise the server user.
     */
    protected string $owner;

    /**
     * Group on the site's files. Non-isolated sites share the web user's
     * group; an isolated site keeps its own group, which the web user is a
     * member of (see SiteIsolationService).
     */
    protected string $group;

    /**
     * Mode for files holding secrets (.env, wp-config.php). On an isolated
     * site the web user is in the site's group, so the group gets no access.
     */
    protected string $secretFileMode;

    /**
     * "sudo " for commands that change ownership or permissions. Empty for
     * isolated sites, whose user already owns everything under its home and
     * runs these commands itself.
     */
    protected string $sudo;

    protected string $siteRoot;

    public function __construct(
        protected SshConnection $connection,
        protected Site $site,
        protected SiteNginxSyncService $siteNginxSyncService,
        protected SourceControlService $sourceControlService,
    ) {
        $this->serverUser = config('server.user');
        $this->owner = $site->systemUser();
        $this->group = $site->isIsolated() ? $site->systemUser() : config('server.web_user');
        $this->secretFileMode = $site->isIsolated() ? '600' : '640';
        $this->sudo = $site->isIsolated() ? '' : 'sudo ';
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
        if ($this->site->isIsolated()) {
            $isolation = app(SiteIsolationService::class);
            $isolation->createUser($this->connection, $this->site);
            $isolation->writePool($this->connection, $this->site, $this->site->php_version ?? '8.4');
        }

        $this->runAsOwner("{$this->sudo}rm -rf {$this->siteRoot}");
        $this->runAsOwner("mkdir -p {$this->siteRoot}/releases {$this->siteRoot}/shared");

        if (! $this->site->isIsolated()) {
            $parentDir = dirname($this->siteRoot);

            $this->connection->exec("sudo chmod 755 {$parentDir}");
            $this->connection->exec("sudo chown -R {$this->serverUser}:{$this->serverUser} {$this->siteRoot}");
        }
    }

    protected function configureNginx(): void
    {
        $this->site->loadMissing('domains');

        // Free-domain sites have nginx configs that reference the wildcard
        // cert at /etc/nginx/ssl/domains/{site_id}/{domain_id}/server.{crt,key}.
        // Ensure those files exist on this server BEFORE SiteNginxSyncService
        // runs `nginx -t` — otherwise nginx refuses to reload and the whole
        // provisioning step aborts.
        $freeSuffix = '.'.config('server.free_domain');
        $syncCert = app(SyncWildcardCertificateForDomainAction::class);

        foreach ($this->site->domains as $domain) {
            if (str_ends_with($domain->hostname, $freeSuffix)) {
                $syncCert->execute(
                    connection: $this->connection,
                    siteId: $this->site->id,
                    domainId: $domain->id,
                );
            }
        }

        $this->siteNginxSyncService->sync($this->site, $this->connection);
    }

    protected function cloneOrPlaceholder(): void
    {
        if ($this->site->repository && $this->site->sourceControlAccount) {
            // Only set up the deploy key + known_hosts here — the real clone
            // happens via the first deploy that ProvisionSiteAction triggers
            // automatically once provisioning finishes (see createBootstrapRelease()).
            $this->sourceControlService->ensureAccountSshKey($this->site->server, $this->site->sourceControlAccount);
            $this->configureSshForGit();
        }

        $this->createBootstrapRelease();
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
     * Base permission model: owner:group ownership, 755 dirs, 644 files.
     * Secret files are locked down again by each project type afterwards.
     */
    protected function setPermissions(): void
    {
        $this->runAsOwner("{$this->sudo}chown -R {$this->owner}:{$this->group} {$this->siteRoot}", timeout: 120);
        $this->runAsOwner("{$this->sudo}find {$this->siteRoot} -type d -exec chmod 755 {} \\;", timeout: 120);
        $this->runAsOwner("{$this->sudo}find {$this->siteRoot} -type f -exec chmod 644 {} \\;", timeout: 120);
    }

    /**
     * Run a command as the site's owner. Non-isolated sites are owned by the
     * server user FlitOps connects as, so the command is sent unchanged.
     */
    protected function runAsOwner(string $command, int $timeout = 30): string
    {
        return $this->connection->exec($this->site->commandAsSystemUser($command), $timeout);
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

    /**
     * Every site starts pointed at a placeholder release, regardless of
     * whether a repository is configured — real code always arrives via the
     * first deploy (ProvisionSiteAction triggers one automatically when a
     * repository is set), so `current` is never left dangling in the gap
     * before that runs.
     */
    protected function createBootstrapRelease(): void
    {
        $releasePath = "{$this->siteRoot}/releases/bootstrap";
        $webDir = ltrim($this->site->directory ?: '/', '/');

        $this->runAsOwner("mkdir -p {$releasePath}");

        if ($webDir && $webDir !== '/') {
            $this->runAsOwner("mkdir -p {$releasePath}/{$webDir}");
        }

        $placeholderPath = $webDir && $webDir !== '/' ? "{$releasePath}/{$webDir}/index.php" : "{$releasePath}/index.php";
        $appName = addslashes((string) config('app.name'));
        $placeholder = <<<PHP
<?php
echo '<h1>Site coming soon!</h1>';
echo "This site is hosted by {$appName}.";
PHP;

        $escapedPlaceholder = str_replace("'", "'\\''", $placeholder);
        $this->runAsOwner("echo '{$escapedPlaceholder}' > {$placeholderPath}");
        $this->runAsOwner("ln -sfn {$releasePath} {$this->siteRoot}/current");
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
