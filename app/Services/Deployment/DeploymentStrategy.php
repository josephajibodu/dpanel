<?php

namespace App\Services\Deployment;

use App\Models\Site;

interface DeploymentStrategy
{
    public function prepend(Site $site): string;

    public function append(Site $site): string;
}
