<?php

namespace App\Services\WebServer;

use App\Contracts\WebServerContract;
use App\Enums\NginxFileSection;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Nginx\NginxConfigService;
use App\Services\Ssh\SshConnection;
use App\Services\Ssh\SshService;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * What Nginx and Caddy do the same way: each domain's main config is rebuilt
 * from the site's current settings on every sync, checked, and only then
 * reloaded. The editor can't change main configs, so there are no edits to
 * keep, and a rebuild always picks up a new PHP version, folder, redirect or
 * certificate.
 */
abstract class BaseWebServer implements WebServerContract
{
    /**
     * How long a change may hold a server's config lock before it's released
     * anyway, so a change that died can't block the server for good.
     */
    private const CONFIG_LOCK_SECONDS = 120;

    /**
     * How long a change waits for one that's already running.
     */
    private const CONFIG_LOCK_WAIT_SECONDS = 20;

    public function __construct(
        protected NginxConfigService $siteConfig,
        protected SshService $sshService,
    ) {}

    /**
     * The main config for one domain of a site.
     */
    abstract protected function generateDomainConfig(Site $site, SiteDomain $domain): string;

    public function withConfigLock(Server $server, Closure $callback): mixed
    {
        try {
            return Cache::lock("web-server-config:{$server->id}", self::CONFIG_LOCK_SECONDS)
                ->block(self::CONFIG_LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            throw new RuntimeException("Another change to the web server config on {$server->name} is still running. Try again in a moment.");
        }
    }

    public function syncSite(Site $site, ?SshConnection $connection = null): void
    {
        $site->loadMissing('server', 'domains');

        $shouldDisconnect = $connection === null;
        $connection ??= $this->sshService->connect($site->server);

        try {
            $this->withConfigLock($site->server, fn () => $this->writeSiteConfigs($site, $connection));
        } finally {
            if ($shouldDisconnect) {
                $connection->disconnect();
            }
        }
    }

    private function writeSiteConfigs(Site $site, SshConnection $connection): void
    {
        $domains = [];
        $configs = [];

        foreach ($site->domains()->where('is_enabled', true)->get() as $domain) {
            $basename = NginxConfigService::configFileName($site, $domain);
            $domains[$basename] = $domain;
            $configs[$basename] = $this->generateDomainConfig($site, $domain);
        }

        /** @var array<string, string> $previous What the last sync wrote, by file name. */
        $previous = $site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->pluck('content', 'path')
            ->map(fn (?string $content): string => (string) $content)
            ->all();

        foreach ($configs as $basename => $config) {
            $this->prepareDomain($connection, $site, $domains[$basename]);
            $this->writeSiteConfig($connection, $basename, $config);
        }

        foreach (array_diff_key($previous, $configs) as $basename => $content) {
            $this->removeSiteConfig($connection, $basename);
        }

        try {
            $this->validate($connection);
        } catch (RuntimeException $exception) {
            $this->restoreSiteConfigs($connection, $previous, $configs);

            throw $exception;
        }

        $this->reload($connection);
        $this->recordSiteConfigs($site, $domains, $configs);
        $this->synchronizeAppUrl($connection, $site);
    }

    public function validateAndReload(SshConnection $connection): void
    {
        $this->validate($connection);
        $this->reload($connection);
    }

    /**
     * Both web servers offer the same two places for a user's own files:
     * inside the site's block, and outside it.
     */
    protected function prepareDomain(SshConnection $connection, Site $site, SiteDomain $domain): void
    {
        $snippetsBase = $domain->nginxSnippetsBasePath();

        $connection->exec("sudo mkdir -p {$snippetsBase}/server {$snippetsBase}/after");
    }

    protected function writeSiteConfig(SshConnection $connection, string $basename, string $config): void
    {
        [$configPath, $linkPaths] = $this->splitConfigPaths($basename);

        $escapedConfig = str_replace("'", "'\\''", $config);
        $connection->exec("echo '{$escapedConfig}' | sudo tee {$configPath} > /dev/null");

        foreach ($linkPaths as $linkPath) {
            $connection->exec("sudo ln -sf {$configPath} {$linkPath}");
        }
    }

    protected function removeSiteConfig(SshConnection $connection, string $basename): void
    {
        $connection->exec('sudo rm -f '.implode(' ', array_reverse($this->siteConfigPaths($basename))));
    }

    /**
     * Put the server back the way the last sync left it, so a config the web
     * server refuses can't stop it reloading for this or any other site.
     *
     * @param  array<string, string>  $previous
     * @param  array<string, string>  $attempted
     */
    private function restoreSiteConfigs(SshConnection $connection, array $previous, array $attempted): void
    {
        foreach ($previous as $basename => $content) {
            $this->writeSiteConfig($connection, $basename, $content);
        }

        foreach (array_diff_key($attempted, $previous) as $basename => $config) {
            $this->removeSiteConfig($connection, $basename);
        }
    }

    /**
     * Keep a copy of what was written, shown read-only in the editor.
     *
     * @param  array<string, SiteDomain>  $domains
     * @param  array<string, string>  $configs
     */
    private function recordSiteConfigs(Site $site, array $domains, array $configs): void
    {
        $site->nginxFiles()
            ->where('section', NginxFileSection::Main->value)
            ->whereNotIn('path', array_keys($configs))
            ->delete();

        foreach ($configs as $basename => $config) {
            $site->nginxFiles()->updateOrCreate(
                ['site_domain_id' => $domains[$basename]->id, 'section' => NginxFileSection::Main->value, 'path' => $basename],
                [
                    'content' => $config,
                    'hash' => md5($config),
                    'last_synced_at' => now(),
                    'sync_status' => 'synced',
                    'sync_error' => null,
                ],
            );
        }
    }

    /**
     * Keep APP_URL in sync with the primary domain when .env exists.
     */
    private function synchronizeAppUrl(SshConnection $connection, Site $site): void
    {
        $primary = $site->domains()->where('is_primary', true)->first();
        if (! $primary) {
            return;
        }

        $envPath = $site->sharedPath().'/.env';
        $url = 'https://'.$primary->hostname;
        $escapedValue = str_replace(['\\', '#', '&'], ['\\\\', '\\#', '\\&'], $url);

        $connection->exec($site->commandAsSystemUser(
            "if [ -f {$envPath} ] && grep -q '^APP_URL=' {$envPath}; then "
            ."sed -i 's#^APP_URL=.*#APP_URL={$escapedValue}#' {$envPath}; "
            ."elif [ -f {$envPath} ]; then "
            ."echo 'APP_URL={$url}' >> {$envPath}; fi"
        ));
    }

    /**
     * @return array{0: string, 1: array<int, string>} The config file, then the links to it.
     */
    private function splitConfigPaths(string $basename): array
    {
        $paths = $this->siteConfigPaths($basename);

        return [array_shift($paths), $paths];
    }
}
