<?php

namespace App\Services\Provisioning;

class NginxService
{
    private string $nginxConf = '/etc/nginx/nginx.conf';

    private string $sitesAvailable = '/etc/nginx/sites-available/app.conf';

    private string $sitesEnabled = '/etc/nginx/sites-enabled/app.conf';

    public function install(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('nginx');
        $context->packages->ensureInstalled('ssl-cert');
        $context->services->enable('nginx');

        $context->files->ensureDirectory('/etc/nginx/sites-available');
        $context->files->ensureDirectory('/etc/nginx/sites-enabled');

        $this->installConfiguration($context);
        $this->installServerConfig($context);

        $this->restart($context);
    }

    public function installConfiguration(ProvisioningContext $context): void
    {
        $context->files->backup($this->nginxConf);

        // For now, use the distribution's nginx.conf and keep this method
        // as a hook for future customisation.
        // We simply ensure the file exists.
        if (! $context->files->exists($this->nginxConf)) {
            $context->files->put($this->nginxConf, "user www-data;\nworker_processes auto;\nerror_log /var/log/nginx/error.log;\nhttp { include /etc/nginx/mime.types; include /etc/nginx/conf.d/*.conf; include /etc/nginx/sites-enabled/*; }\n");
        }
    }

    public function installServerConfig(ProvisioningContext $context): void
    {
        $config = self::buildServerConfig($context->serverUser, $context->server->php_version);

        $context->files->put($this->sitesAvailable, $config);

        // Remove default site if present.
        if ($context->files->exists('/etc/nginx/sites-enabled/default')) {
            $context->files->delete('/etc/nginx/sites-enabled/default');
        }

        $context->files->symlink($this->sitesAvailable, $this->sitesEnabled);
    }

    /**
     * Build the catch-all vhost config: a plain-HTTP `default_server` for the
     * placeholder landing page, plus an HTTPS `default_server` that rejects
     * the TLS handshake outright.
     *
     * Every site vhost is a separate file included via `sites-enabled/*`, and
     * nginx silently falls back to whichever 443 server block loaded first
     * (alphabetically) for any SNI/Host it can't match to a site — e.g. a
     * site whose own vhost hasn't synced yet, or ever failed to. Without an
     * explicit 443 default_server, that fallback ends up being an arbitrary
     * *other* customer's site: their certificate gets presented for a
     * hostname it doesn't cover, and if that site redirects to a canonical
     * domain, the visitor is bounced there. This file is named "app.conf" so
     * it keeps sorting first; the block below ensures unmatched HTTPS
     * requests are rejected instead of silently landing on someone else's
     * site.
     */
    public static function buildServerConfig(string $serverUser, string $phpVersion): string
    {
        return <<<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /home/{$serverUser}/sites;
    index index.php index.html;

    server_name _;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{$phpVersion}-fpm-{$serverUser}.sock;
    }

    location ~ /\\.ht {
        deny all;
    }
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;

    ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;
    ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;

    server_name _;

    return 444;
}
NGINX;
    }

    public function updatePort(ProvisioningContext $context, string $port): void
    {
        $config = $context->files->get($this->sitesAvailable);
        $config = str_replace('listen 80 default_server;', "listen {$port} default_server;", $config);
        $config = str_replace('listen [::]:80 default_server;', "listen [::]:{$port} default_server;", $config);

        $context->files->put($this->sitesAvailable, $config);

        $this->restart($context);
    }

    public function restart(ProvisioningContext $context): void
    {
        $context->services->restart('nginx');
    }

    public function stop(ProvisioningContext $context): void
    {
        $context->services->stop('nginx');
    }
}
