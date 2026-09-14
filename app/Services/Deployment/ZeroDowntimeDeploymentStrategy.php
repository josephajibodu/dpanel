<?php

namespace App\Services\Deployment;

use App\Models\Site;

/**
 * Every deploy clones into a fresh releases/{ulid} directory and atomically
 * swaps the `current` symlink onto it once the build succeeds, instead of
 * mutating the site's live checkout in place. This is what makes deploys
 * zero-downtime and rollback possible (RollbackSiteJob reuses swapAndReload()
 * to point `current` back at an older release without rebuilding anything).
 */
class ZeroDowntimeDeploymentStrategy implements DeploymentStrategy
{
    public function prepend(Site $site): string
    {
        $lines = [
            'mkdir -p "$RELEASE_PATH"',
            $this->cloneCommand($site),
            'cd "$RELEASE_PATH"',
            $this->symlinkSharedPaths($site),
        ];

        return implode("\n", array_filter($lines, fn (string $line) => $line !== ''))."\n";
    }

    public function append(Site $site): string
    {
        $chown = 'sudo chown -R $SERVER_USER:$WEB_USER "$RELEASE_PATH"';
        $storageChmod = 'if [ -d "$RELEASE_PATH/storage" ]; then sudo chmod -R 775 "$RELEASE_PATH/storage"; fi';
        $cacheChmod = 'if [ -d "$RELEASE_PATH/bootstrap/cache" ]; then sudo chmod -R 775 "$RELEASE_PATH/bootstrap/cache"; fi';

        return "\n{$chown}\n{$storageChmod}\n{$cacheChmod}\n".$this->swapAndReload($site, '"$RELEASE_PATH"');
    }

    public function swapAndReload(Site $site, string $releasePathExpr): string
    {
        $currentPath = $site->currentPath();

        $swap = "ln -sfn {$releasePathExpr} {$currentPath}";
        $reload = "( flock -w 10 9 || exit 1\n    echo 'Restarting FPM...'; sudo -S service \$PHP_FPM reload ) 9>/tmp/fpmlock";

        return "{$swap}\n{$reload}";
    }

    private function cloneCommand(Site $site): string
    {
        $serverUser = config('server.user');
        $sshKeyPath = "/home/{$serverUser}/.ssh/id_ed25519";
        $repoUrl = $site->gitCloneUrl();

        $gitCommand = "GIT_SSH_COMMAND='ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new' git clone --branch {$site->branch} {$repoUrl} \"\$RELEASE_PATH\"";

        return "{$gitCommand}\ngit -C \"\$RELEASE_PATH\" config --global --add safe.directory \"\$RELEASE_PATH\"";
    }

    /**
     * Symlink every shared file/directory this project type needs (env
     * files, writable storage, uploads, ...) into the freshly cloned
     * release. Optional targets that were never created (e.g. the sqlite
     * file when the site uses a real server database instead) are skipped
     * rather than left as dangling symlinks.
     */
    private function symlinkSharedPaths(Site $site): string
    {
        $symlinks = $site->project_type->sharedSymlinks();

        if ($symlinks === []) {
            return '';
        }

        $sharedPath = rtrim($site->sharedPath(), '/');
        $lines = [];

        foreach ($symlinks as $releaseRelative => $sharedRelative) {
            $sharedAbsolute = "{$sharedPath}/{$sharedRelative}";
            $releaseDir = dirname($releaseRelative);

            if ($releaseDir !== '.') {
                $lines[] = "mkdir -p \"\$RELEASE_PATH/{$releaseDir}\"";
            }

            $lines[] = "if [ -e '{$sharedAbsolute}' ]; then ln -sfn '{$sharedAbsolute}' \"\$RELEASE_PATH/{$releaseRelative}\"; fi";
        }

        return implode("\n", $lines);
    }
}
