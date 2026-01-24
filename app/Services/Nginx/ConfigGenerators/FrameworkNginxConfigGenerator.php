<?php

namespace App\Services\Nginx\ConfigGenerators;

use App\Models\Site;

class FrameworkNginxConfigGenerator extends BaseNginxConfigGenerator
{
    /**
     * Generate the server block content for Laravel/Symfony frameworks.
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

    # Handle static files
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

{$this->getCommonLocations()}

    error_page 404 /index.php;

{$this->getPhpFpmConfig($phpVersion)}

{$this->getHiddenFilesProtection()}
NGINX;
    }
}
