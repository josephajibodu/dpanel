<?php

namespace App\Services\Deployment;

use App\Models\Site;

class SimpleDeploymentStrategy implements DeploymentStrategy
{
    public function prepend(Site $site): string
    {
        return "cd \$SITE_ROOT\n";
    }

    public function append(Site $site): string
    {
        $phpVersion = $site->php_version ?? '8.4';
        $phpFpm = "php{$phpVersion}-fpm";

        return "\n( flock -w 10 9 || exit 1\n    echo 'Restarting FPM...'; sudo -S service {$phpFpm} reload ) 9>/tmp/fpmlock";
    }
}
