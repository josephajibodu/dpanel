<?php

namespace App\Services\Provisioning;

/**
 * Installs Caddy from its official apt repository and sets it up the way
 * flitops expects: one config file per site domain under /etc/caddy/sites,
 * and Caddy running as the same web user Nginx would (so PHP-FPM sockets,
 * site file permissions and isolated sites work unchanged).
 */
class CaddyService
{
    public const CADDYFILE = '/etc/caddy/Caddyfile';

    public const SITES_DIRECTORY = '/etc/caddy/sites';

    public const RELOAD_SCRIPT = '/usr/local/sbin/flitops-caddy-reload';

    public const DATA_DIRECTORY = '/var/lib/caddy';

    public function install(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('debian-keyring');
        $context->packages->ensureInstalled('debian-archive-keyring');
        $context->packages->ensureInstalled('apt-transport-https');
        $context->packages->ensureInstalled('curl');

        $context->runner->runQuietly(
            "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg",
            120,
        );
        $context->runner->runQuietly(
            "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list",
            120,
        );
        $context->runner->runQuietly('chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg /etc/apt/sources.list.d/caddy-stable.list', 30);
        $context->runner->runQuietly('apt-get -o DPkg::Lock::Timeout=60 update', 600);
        $context->packages->ensureInstalled('caddy');

        $webUser = (string) config('server.web_user');

        $context->files->ensureDirectory('/etc/systemd/system/caddy.service.d');
        $context->files->put('/etc/systemd/system/caddy.service.d/flitops.conf', self::buildServiceOverride($webUser));

        $context->files->ensureDirectory(self::SITES_DIRECTORY);
        $context->files->ensureDirectory('/etc/caddy/flitops-conf');
        $context->files->ensureDirectory('/etc/caddy/ssl');
        $context->files->put(self::CADDYFILE, self::buildCaddyfile());

        $context->files->put(self::RELOAD_SCRIPT, self::buildReloadScript($webUser));
        $context->runner->runQuietly('chmod 755 '.self::RELOAD_SCRIPT, 30);

        $dataDirectory = self::DATA_DIRECTORY;
        $context->runner->runQuietly(
            "mkdir -p {$dataDirectory}/.local/share {$dataDirectory}/.config && chown -R {$webUser}:{$webUser} {$dataDirectory}",
            30,
        );

        $context->runner->runQuietly('systemctl daemon-reload', 60);
        $context->services->enable('caddy');
        $context->services->restart('caddy');
    }

    /**
     * Run Caddy as the web user instead of the package's own `caddy` user,
     * keeping its certificates and state in its usual data directory.
     */
    public static function buildServiceOverride(string $webUser): string
    {
        $dataDirectory = self::DATA_DIRECTORY;

        return <<<INI
# Managed by FlitOps
[Service]
User={$webUser}
Group={$webUser}
Environment=XDG_DATA_HOME={$dataDirectory}/.local/share
Environment=XDG_CONFIG_HOME={$dataDirectory}/.config

INI;
    }

    /**
     * The main Caddyfile: a catch-all that answers 404 for hostnames with no
     * site, then every site's own config. Unknown HTTPS hostnames get no
     * certificate, so another site's certificate is never served for them.
     */
    public static function buildCaddyfile(): string
    {
        $sitesDirectory = self::SITES_DIRECTORY;

        return <<<CADDY
# Managed by FlitOps - DO NOT EDIT
# Each site domain has its own file in {$sitesDirectory}.

:80 {
	respond "No site is configured for this address." 404
}

import {$sitesDirectory}/*.conf

CADDY;
    }

    /**
     * Reload Caddy, or restart it when the web user has joined groups since
     * Caddy started. A running process keeps the groups it started with, and
     * an isolated site's files are only readable through its own group.
     */
    public static function buildReloadScript(string $webUser): string
    {
        return <<<SH
#!/bin/sh
# Managed by FlitOps
set -e
pid=\$(systemctl show -p MainPID --value caddy)
wanted=\$(id -G {$webUser} | tr ' ' '\\n' | sort -n | tr '\\n' ' ')
running=\$(awk '/^Groups:/ { for (i = 2; i <= NF; i++) print \$i }' "/proc/\$pid/status" 2>/dev/null | sort -n | tr '\\n' ' ')

if [ "\$pid" != "0" ] && [ "\$wanted" = "\$running" ]; then
    systemctl reload caddy
else
    systemctl restart caddy
fi

SH;
    }
}
