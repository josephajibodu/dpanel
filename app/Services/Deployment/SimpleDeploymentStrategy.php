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

        // Re-establish ownership after the deploy script runs. composer/npm
        // create vendor/ and node_modules/ as $SERVER_USER:$SERVER_USER, but
        // php-fpm runs as $WEB_USER and storage/ + bootstrap/cache need to be
        // group-writable by it. This mirrors BaseSiteProvisioner::setPermissions.
        $chown = "sudo chown -R \$SERVER_USER:\$WEB_USER \$SITE_ROOT";
        $storageChmod = "if [ -d \$SITE_ROOT/storage ]; then sudo chmod -R 775 \$SITE_ROOT/storage; fi";
        $cacheChmod = "if [ -d \$SITE_ROOT/bootstrap/cache ]; then sudo chmod -R 775 \$SITE_ROOT/bootstrap/cache; fi";

        return "\n{$chown}\n{$storageChmod}\n{$cacheChmod}\n( flock -w 10 9 || exit 1\n    echo 'Restarting FPM...'; sudo -S service {$phpFpm} reload ) 9>/tmp/fpmlock";
    }
}
