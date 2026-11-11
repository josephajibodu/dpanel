<?php

namespace App\Services\Nginx\ConfigGenerators;

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

{$this->rootLocation($site)}

{$this->getCommonLocations()}

{$this->getHiddenFilesProtection()}
{$this->missingAssetLocation($site)}
NGINX;
    }

    /**
     * A single-page app gets its index.html for URLs that match no file.
     * index.html is listed before =404, so a site without one answers 404
     * instead of looping back into this location.
     */
    private function rootLocation(Site $site): string
    {
        if ($site->servesAsSinglePageApp()) {
            return <<<'NGINX'
    # Single-page app: URLs that match no file get index.html, and the app shows the page
    location / {
        try_files $uri $uri/ /index.html =404;
    }
NGINX;
        }

        return <<<'NGINX'
    # Handle static files
    location / {
        try_files $uri $uri/ =404;
    }
NGINX;
    }

    /**
     * Keeps a missing asset of a single-page app a 404 (see
     * Site::SINGLE_PAGE_APP_ASSET_EXTENSIONS). It comes after the hidden
     * files rule, since Nginx uses the first matching regex location and
     * this one must never serve a file that rule denies.
     */
    private function missingAssetLocation(Site $site): string
    {
        if (! $site->servesAsSinglePageApp()) {
            return '';
        }

        $extensions = implode('|', Site::SINGLE_PAGE_APP_ASSET_EXTENSIONS);

        return <<<NGINX

    # A missing asset is a 404, not the app's page
    location ~* \.(?:{$extensions})$ {
        try_files \$uri =404;
    }
NGINX;
    }
}
