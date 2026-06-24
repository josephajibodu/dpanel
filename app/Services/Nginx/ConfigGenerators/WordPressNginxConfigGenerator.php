<?php

namespace App\Services\Nginx\ConfigGenerators;

use App\Models\Site;

class WordPressNginxConfigGenerator extends BaseNginxConfigGenerator
{
    /**
     * Generate the server block content for WordPress sites.
     */
    protected function generateServerBlock(Site $site): string
    {
        $webRoot = $site->webRoot();
        $phpVersion = $site->php_version ?: '8.3';

        return <<<NGINX
    root {$webRoot};

{$this->getSecurityHeaders()}

    index index.php index.html index.htm;

    charset utf-8;

    # WordPress permalink structure
    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    # WordPress admin and login
    location ~ ^/wp-admin/ {
        try_files \$uri \$uri/ /index.php?\$args;
    }

{$this->getCommonLocations()}

    # PHP-FPM configuration
    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/run/php/php{$phpVersion}-fpm-{$this->serverUser()}.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

{$this->getHiddenFilesProtection()}

    # Deny access to wp-config.php
    location ~ /wp-config.php {
        deny all;
    }
NGINX;
    }
}
