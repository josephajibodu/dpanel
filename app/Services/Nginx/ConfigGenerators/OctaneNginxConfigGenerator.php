<?php

namespace App\Services\Nginx\ConfigGenerators;

use App\Models\Site;

class OctaneNginxConfigGenerator extends BaseNginxConfigGenerator
{
    /**
     * Generate the server block content for a Laravel site that runs on
     * Octane: files that exist are served directly, and every other request
     * is handed to the site's Octane process on its local port.
     */
    protected function generateServerBlock(Site $site): string
    {
        $webRoot = $site->webRoot();

        return <<<NGINX
    root {$webRoot};

{$this->getSecurityHeaders()}

    index index.php;

    charset utf-8;

    # Files that exist are served directly, everything else goes to the Octane process
    location / {
        try_files \$uri \$uri/ @octane;
    }

    # PHP files are never served as files
    location ~ \.php$ {
        try_files /not_exists @octane;
    }

{$this->getCommonLocations()}

    error_page 404 /index.php;

    location @octane {
        set \$suffix "";

        if (\$uri = /index.php) {
            set \$suffix ?\$query_string;
        }

        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        proxy_set_header Connection "";
        proxy_buffer_size 32k;
        proxy_buffers 16 16k;

        proxy_pass http://127.0.0.1:{$site->octane_port}\$suffix;
    }

{$this->getHiddenFilesProtection()}
NGINX;
    }
}
