<?php

namespace App\Services\Deployment;

use App\Models\Site;

interface DeploymentStrategy
{
    public function prepend(Site $site): string;

    public function append(Site $site): string;

    /**
     * Atomically point `current` at the given release and reload PHP-FPM.
     * Embeddable as its own script fragment so RollbackSiteJob can run just
     * the swap without a clone/build (that's the entire point of a rollback).
     *
     * @param  string  $releasePathExpr  A shell expression for the release path (e.g. "$RELEASE_PATH" or a literal path).
     */
    public function swapAndReload(Site $site, string $releasePathExpr): string;
}
