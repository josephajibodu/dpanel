<?php

namespace App\Services\Deployment;

use App\Models\Site;

interface DeploymentStrategy
{
    public function prepend(Site $site): string;

    public function append(Site $site): string;

    /**
     * Atomically point `current` at $RELEASE_PATH, reload PHP-FPM, then restart
     * the project type's long-running processes from that release. Relies on
     * the deploy script preamble's variables ($RELEASE_PATH, $PHP_FPM, $PHP).
     * Embeddable as its own script fragment so RollbackSiteJob can activate an
     * already-built release without a clone/build (that's the entire point of a rollback).
     */
    public function activate(Site $site): string;
}
