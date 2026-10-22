<?php

namespace App\Services\Deployment;

use App\Models\Site;

interface DeploymentStrategy
{
    /**
     * Runs before the editable deploy script, as the site's own user.
     */
    public function prepend(Site $site): string;

    /**
     * Runs after the editable deploy script, as the site's own user.
     */
    public function append(Site $site): string;

    /**
     * Atomically point `current` at $RELEASE_PATH, reload PHP-FPM, then restart
     * the project type's long-running processes from that release. Relies on
     * the deploy script preamble's variables ($RELEASE_PATH, $PHP_FPM, $PHP).
     * Embeddable as its own script fragment so RollbackSiteJob can activate an
     * already-built release without a clone/build (that's the entire point of a rollback).
     *
     * Isolated sites can't use sudo, so their fragment leaves the PHP-FPM
     * reload out; the deploy and rollback jobs run reloadPhpFpm() afterwards
     * as the server user.
     */
    public function activate(Site $site): string;

    /**
     * Reload PHP-FPM so it stops serving the previous release's cached code.
     * Run as the server user (it needs sudo).
     */
    public function reloadPhpFpm(Site $site): string;

    /**
     * Fetch an isolated site's code into $RELEASE_PATH. Run as the server
     * user before the deploy script, so the site's own user never needs a
     * git key.
     */
    public function cloneForIsolatedSite(Site $site): string;
}
