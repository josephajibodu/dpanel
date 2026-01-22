<?php

namespace App\Services\Nginx;

use App\Models\Site;

class StaticNginxConfigGenerator extends BaseNginxConfigGenerator
{
    /**
     * Generate the server block content for static HTML sites.
     */
    protected function generateServerBlock(Site $site): string
    {
        $webRoot = $site->webRoot();

        return <<<NGINX
    root {$webRoot};

{$this->getSecurityHeaders()}

    index index.html index.htm;

    charset utf-8;

    # Handle static files
    location / {
        try_files \$uri \$uri/ =404;
    }

{$this->getCommonLocations()}

{$this->getHiddenFilesProtection()}
NGINX;
    }
}
