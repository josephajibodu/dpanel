<?php

namespace App\Services\Nginx;

use App\Models\Site;

class PhpNginxConfigGenerator extends BaseNginxConfigGenerator
{
    /**
     * Generate the server block content for generic PHP applications.
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

    # Handle static files and directories
    location / {
        try_files \$uri \$uri/ /index.php /index.html =404;
    }

{$this->getCommonLocations()}

    # PHP-FPM configuration
    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:/var/run/php/php{$phpVersion}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

{$this->getHiddenFilesProtection()}
NGINX;
    }
}
