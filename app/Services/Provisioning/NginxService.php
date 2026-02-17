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
        $serverUser = $context->serverUser;
        $phpVersion = $context->server->php_version;

        $config = <<<NGINX
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
NGINX;

        $context->files->put($this->sitesAvailable, $config);

        // Remove default site if present.
        if ($context->files->exists('/etc/nginx/sites-enabled/default')) {
            $context->files->delete('/etc/nginx/sites-enabled/default');
        }

        $context->files->symlink($this->sitesAvailable, $this->sitesEnabled);
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
