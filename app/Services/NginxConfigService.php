<?php

namespace App\Services;

use App\Models\Site;
use App\Services\Nginx\NginxConfigGeneratorFactory;

class NginxConfigService
{
    /**
     * Generate the Nginx configuration for a site.
     */
    public function generate(Site $site): string
    {
        $generator = NginxConfigGeneratorFactory::make($site);

        return $generator->generate($site, false);
    }

    /**
     * Generate SSL-enabled Nginx configuration.
     */
    public function generateWithSsl(Site $site): string
    {
        $generator = NginxConfigGeneratorFactory::make($site);

        return $generator->generate($site, true);
    }

    /**
     * Get the path where the Nginx config should be stored on the server.
     */
    public function configPath(Site $site): string
    {
        return "/etc/nginx/sites-available/{$site->domain}";
    }

    /**
     * Get the path for the symlink in sites-enabled.
     */
    public function enabledPath(Site $site): string
    {
        return "/etc/nginx/sites-enabled/{$site->domain}";
    }
}
