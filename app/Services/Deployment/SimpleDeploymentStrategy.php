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
        // $PHP_FPM is set in the deploy preamble by DeploySiteJob via PhpRuntimeResolver.
        $chown = 'sudo chown -R $SERVER_USER:$WEB_USER $SITE_ROOT';
        $storageChmod = 'if [ -d $SITE_ROOT/storage ]; then sudo chmod -R 775 $SITE_ROOT/storage; fi';
        $cacheChmod = 'if [ -d $SITE_ROOT/bootstrap/cache ]; then sudo chmod -R 775 $SITE_ROOT/bootstrap/cache; fi';

        return "\n{$chown}\n{$storageChmod}\n{$cacheChmod}\n( flock -w 10 9 || exit 1\n    echo 'Restarting FPM...'; sudo -S service \$PHP_FPM reload ) 9>/tmp/fpmlock";
    }
}
