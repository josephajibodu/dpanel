<?php

namespace App\Services\Nginx\ConfigGenerators;

use App\Models\Site;

class PhpNginxConfigGenerator extends BaseNginxConfigGenerator
{
    /**
     * Generate the server block content for generic PHP applications.
     *
     * index.php must stay the last entry of the `location /` try_files.
     * Nginx serves every earlier entry it finds as a plain file from that
     * location, which would send visitors the PHP source. Only the last
     * entry is looked up again, so it reaches the PHP-FPM location, which
     * answers 404 when the site has no index.php.
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

    # Serve files and directories that exist, hand everything else to index.php
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

{$this->getCommonLocations()}

    # PHP-FPM configuration
    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_pass unix:{$site->phpFpmSocket($phpVersion)};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

{$this->getHiddenFilesProtection()}
NGINX;
    }
}
