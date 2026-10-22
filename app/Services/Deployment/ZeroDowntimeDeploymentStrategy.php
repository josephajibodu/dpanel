<?php

namespace App\Services\Deployment;

use App\Models\Site;

/**
 * Every deploy clones into a fresh releases/{ulid} directory and atomically
 * swaps the `current` symlink onto it once the build succeeds, instead of
 * mutating the site's live checkout in place. This is what makes deploys
 * zero-downtime and rollback possible (RollbackSiteJob reuses activate() to
 * point `current` back at an older release without rebuilding anything).
 */
class ZeroDowntimeDeploymentStrategy implements DeploymentStrategy
{
    public function prepend(Site $site): string
    {
        $lines = $site->isIsolated()
            ? ['cd "$RELEASE_PATH"', $this->symlinkSharedPaths($site)]
            : [
                'mkdir -p "$RELEASE_PATH"',
                $this->cloneCommand($site),
                'cd "$RELEASE_PATH"',
                $this->symlinkSharedPaths($site),
            ];

        return implode("\n", array_filter($lines, fn (string $line) => $line !== ''))."\n";
    }

    public function append(Site $site): string
    {
        if ($site->isIsolated()) {
            $storageChmod = 'if [ -d "$RELEASE_PATH/storage" ]; then chmod -R 775 "$RELEASE_PATH/storage"; fi';
            $cacheChmod = 'if [ -d "$RELEASE_PATH/bootstrap/cache" ]; then chmod -R 775 "$RELEASE_PATH/bootstrap/cache"; fi';

            return "\n{$storageChmod}\n{$cacheChmod}\n".$this->activate($site);
        }

        $chown = 'sudo chown -R $SERVER_USER:$WEB_USER "$RELEASE_PATH"';
        $storageChmod = 'if [ -d "$RELEASE_PATH/storage" ]; then sudo chmod -R 775 "$RELEASE_PATH/storage"; fi';
        $cacheChmod = 'if [ -d "$RELEASE_PATH/bootstrap/cache" ]; then sudo chmod -R 775 "$RELEASE_PATH/bootstrap/cache"; fi';

        return "\n{$chown}\n{$storageChmod}\n{$cacheChmod}\n".$this->activate($site);
    }

    public function activate(Site $site): string
    {
        $currentPath = $site->currentPath();

        $swap = "ln -sfn \"\$RELEASE_PATH\" {$currentPath}";

        $script = $site->isIsolated() ? $swap : "{$swap}\n".$this->reloadPhpFpm($site);

        $postActivation = $site->project_type->postActivationScript();

        if ($postActivation !== '') {
            $script .= "\ncd \"\$RELEASE_PATH\"\n{$postActivation}";
        }

        return $script;
    }

    public function reloadPhpFpm(Site $site): string
    {
        return "( flock -w 10 9 || exit 1\n    echo 'Restarting FPM...'; sudo -S service \$PHP_FPM reload ) 9>/tmp/fpmlock";
    }

    /**
     * The site's user never holds a git key: FlitOps clones as the server
     * user into a private temporary directory, then streams the files to
     * the site user, who unpacks them into the release directory.
     */
    public function cloneForIsolatedSite(Site $site): string
    {
        $unpack = $site->commandAsSystemUser('mkdir -p "$1" && tar -C "$1" -xf -').' _ "$RELEASE_PATH"';

        return implode("\n", [
            'CLONE_DIR=$(mktemp -d)',
            'trap \'rm -rf "$CLONE_DIR"\' EXIT',
            $this->gitCloneCommand($site, '"$CLONE_DIR/release"'),
            'tar -C "$CLONE_DIR/release" -cf - . | '.$unpack,
        ])."\n";
    }

    private function cloneCommand(Site $site): string
    {
        $gitCommand = $this->gitCloneCommand($site, '"$RELEASE_PATH"');

        return "{$gitCommand}\ngit -C \"\$RELEASE_PATH\" config --global --add safe.directory \"\$RELEASE_PATH\"";
    }

    /**
     * Clone with the server user's key, which is the one added to the
     * source control account.
     */
    private function gitCloneCommand(Site $site, string $target): string
    {
        $serverUser = config('server.user');
        $sshKeyPath = "/home/{$serverUser}/.ssh/id_ed25519";
        $repoUrl = $site->gitCloneUrl();

        return "GIT_SSH_COMMAND='ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new' git clone --branch {$site->branch} {$repoUrl} {$target}";
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
